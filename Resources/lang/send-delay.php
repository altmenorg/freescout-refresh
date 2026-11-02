<?php

/*
 * Toast after sending a reply: FreeScout says "Email Sent · View or Undo", but the e-mail is queued for
 * Conversation::UNDO_TIMOUT seconds and the toast only shows while it can still be undone. Refresh says "Sending in
 * N s · View or Undo" instead, fully translated in every language FreeScout ships (RefreshServiceProvider::
 * overrideSendToast()): FreeScout's own files leave "View or Undo" in English in several of them (ar, da, fa, ko),
 * "or" in others (fi, ja, pl, pt-BR), and split the Japanese one over three lines.
 * [sending (%d = seconds), view, or, undo]
 */
return [
    'en'    => ['Sending in %d s', 'View', 'or', 'Undo'],
    'ar'    => ['الإرسال خلال %d ثانية', 'عرض', 'أو', 'تراجع'],
    'az'    => ['%d san. sonra göndəriləcək', 'Bax', 'və ya', 'Geri qaytar'],
    'ca'    => ['Enviament en %d s', 'Veure', 'o', 'Desfés'],
    'cs'    => ['Odeslání za %d s', 'Zobrazit', 'nebo', 'Vrátit zpět'],
    'da'    => ['Sendes om %d s', 'Vis', 'eller', 'Fortryd'],
    'de'    => ['Wird in %d s gesendet', 'Ansehen', 'oder', 'Rückgängig'],
    'es'    => ['Envío en %d s', 'Ver', 'o', 'Deshacer'],
    'fa'    => ['ارسال تا %d ثانیه دیگر', 'مشاهده', 'یا', 'لغو'],
    'fi'    => ['Lähetetään %d s kuluttua', 'Näytä', 'tai', 'Kumoa'],
    'fr'    => ['Envoi dans %d s', 'Voir', 'ou', 'Annuler'],
    'he'    => ['שליחה בעוד %d שניות', 'הצג', 'או', 'בטל'],
    'hr'    => ['Slanje za %d s', 'Pogledaj', 'ili', 'Poništi'],
    'hu'    => ['Küldés %d mp múlva', 'Megtekintés', 'vagy', 'Visszavonás'],
    'it'    => ['Invio tra %d s', 'Visualizza', 'o', 'Annulla'],
    'ja'    => ['%d 秒後に送信', '表示', 'または', '元に戻す'],
    'ko'    => ['%d초 후 전송', '보기', '또는', '실행 취소'],
    'kz'    => ['%d с кейін жіберіледі', 'Көрсету', 'немесе', 'Бас тарту'],
    'nl'    => ['Verzenden over %d s', 'Bekijken', 'of', 'Ongedaan maken'],
    'no'    => ['Sendes om %d s', 'Vis', 'eller', 'Angre'],
    'pl'    => ['Wysyłanie za %d s', 'Podgląd', 'lub', 'Cofnij'],
    'pt-BR' => ['Envio em %d s', 'Ver', 'ou', 'Desfazer'],
    'pt-PT' => ['Envio dentro de %d s', 'Ver', 'ou', 'Anular'],
    'ro'    => ['Trimitere în %d s', 'Vizualizare', 'sau', 'Anulare'],
    'ru'    => ['Отправка через %d с', 'Показать', 'или', 'Отменить'],
    'sk'    => ['Odoslanie o %d s', 'Zobraziť', 'alebo', 'Vrátiť späť'],
    'sl'    => ['Pošiljanje čez %d s', 'Ogled', 'ali', 'Razveljavi'],
    'sv'    => ['Skickas om %d s', 'Visa', 'eller', 'Ångra'],
    'tr'    => ['%d sn içinde gönderilecek', 'Görüntüle', 'veya', 'Geri al'],
    'uk'    => ['Надсилання через %d с', 'Переглянути', 'або', 'Скасувати'],
    'zh-CN' => ['%d 秒后发送', '查看', '或', '撤销'],
    'zh-TW' => ['%d 秒後傳送', '檢視', '或', '復原'],
];
