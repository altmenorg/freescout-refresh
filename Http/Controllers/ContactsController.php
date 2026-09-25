<?php

namespace Modules\Refresh\Http\Controllers;

use App\Customer;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * "All contacts" page, Freshdesk clone: table Contact / Title / Company / Email / Mobile phone /
 * Work phone, search, pagination of 30, CSV export. Alphabetical sort, like Freshdesk.
 */
class ContactsController extends Controller
{
    const PER_PAGE = 30;

    protected function query(Request $request)
    {
        $q = trim((string)$request->input('q', ''));
        $prefix = \DB::getTablePrefix();
        $query = Customer::query()
            ->select('customers.*')
            ->selectRaw("(SELECT MIN(e.email) FROM {$prefix}emails e WHERE e.customer_id = {$prefix}customers.id) AS rf_email");
        if ($q !== '') {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $q).'%';
            $digits = preg_replace('/\D/', '', $q);
            $query->where(function ($w) use ($like, $digits) {
                $w->where('customers.first_name', 'like', $like)
                    ->orWhere('customers.last_name', 'like', $like)
                    ->orWhereRaw("CONCAT(customers.first_name, ' ', customers.last_name) LIKE ?", [$like])
                    ->orWhere('customers.company', 'like', $like)
                    ->orWhereIn('customers.id', function ($s) use ($like) {
                        $s->select('customer_id')->from('emails')->where('email', 'like', $like);
                    });
                if (strlen($digits) >= 4) {
                    $w->orWhere('customers.phones', 'like', '%'.$digits.'%');
                }
            });
        }
        return $query->orderByRaw("TRIM(CONCAT(COALESCE(customers.first_name, ''), ' ', COALESCE(customers.last_name, ''))) = ''")
            // names starting with punctuation ("- Smith", ".john") after the others, not at the top of the list
            ->orderByRaw("TRIM(CONCAT(COALESCE(customers.first_name, ''), COALESCE(customers.last_name, ''))) REGEXP '^[^[:alnum:]]'")
            ->orderBy('customers.first_name')->orderBy('customers.last_name');
    }

    /** Phones by type: [mobile, work] (FreeScout types 4 = mobile, 1 = work). */
    public static function phones($customer)
    {
        $mobile = [];
        $work = [];
        foreach ($customer->getPhones() as $p) {
            if (empty($p['value'])) {
                continue;
            }
            if ((int)($p['type'] ?? 0) === Customer::PHONE_TYPE_MOBILE) {
                $mobile[] = $p['value'];
            } else {
                $work[] = $p['value'];
            }
        }
        return [implode(', ', $mobile), implode(', ', $work)];
    }

    public function index(Request $request)
    {
        $contacts = $this->query($request)->paginate(self::PER_PAGE)->appends($request->except('page'));
        return view('refresh::contacts', [
            'contacts' => $contacts,
            'q'        => (string)$request->input('q', ''),
        ]);
    }

    public function export(Request $request)
    {
        $query = $this->query($request);
        return response()->stream(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [__('Contact'), __('Job title'), __('Company'), __('E-mail address'), __('Mobile phone'), __('Work phone')], ';');
            $query->chunk(500, function ($rows) use ($out) {
                foreach ($rows as $c) {
                    list($mobile, $work) = self::phones($c);
                    fputcsv($out, [$c->getFullName(), $c->job_title, $c->company, $c->rf_email, $mobile, $work], ';');
                }
            });
            fclose($out);
        }, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="contacts-'.date('Y-m-d').'.csv"',
        ]);
    }
}
