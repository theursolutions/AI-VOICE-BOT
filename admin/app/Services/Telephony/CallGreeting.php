<?php

namespace App\Services\Telephony;

use App\Models\Project;
use App\Models\Session;

/**
 * The first thing the AI says on a call, in the agent's call language.
 *
 * Defaults say "we" ("hum … kar sakte hain") so they read right in a male
 * or a female voice.
 */
class CallGreeting
{
    public const DEFAULTS = [
        'en'    => 'Hi! Thanks for calling. How can I help you?',
        'ur'    => 'السلام علیکم! کال کرنے کا شکریہ۔ بتائیے، ہم آپ کی کیا مدد کر سکتے ہیں؟',
        'roman' => 'Assalam o Alaikum! Call karne ka shukriya. Bataiye, hum aap ki kya madad kar sakte hain?',
        'pa'    => 'سلام! فون کرن دا شکریہ۔ دسو، اسیں تہاڈی کی مدد کر سکدے آں؟',
        'sd'    => 'سلام! فون ڪرڻ جي مهرباني. ٻڌايو، اسان توهان جي ڪهڙي مدد ڪري سگهون ٿا؟',
        'ps'    => 'السلام علیکم! د زنګ وهلو مننه. ووایاست، موږ ستاسو څنګه مرسته کولی شو؟',
        'hi'    => 'नमस्ते! कॉल करने के लिए धन्यवाद। बताइए, हम आपकी क्या मदद कर सकते हैं?',
        'bn'    => 'নমস্কার! কল করার জন্য ধন্যবাদ। বলুন, আমরা আপনাকে কীভাবে সাহায্য করতে পারি?',
        'ta'    => 'வணக்கம்! அழைத்ததற்கு நன்றி. நாங்கள் உங்களுக்கு எப்படி உதவலாம்?',
        'te'    => 'నమస్కారం! కాల్ చేసినందుకు ధన్యవాదాలు. మేము మీకు ఎలా సహాయం చేయగలం?',
        'mr'    => 'नमस्कार! कॉल केल्याबद्दल धन्यवाद. सांगा, आम्ही तुमची कशी मदत करू शकतो?',
        'gu'    => 'નમસ્તે! કૉલ કરવા બદલ આભાર. કહો, અમે તમારી શું મદદ કરી શકીએ?',
        'kn'    => 'ನಮಸ್ಕಾರ! ಕರೆ ಮಾಡಿದ್ದಕ್ಕೆ ಧನ್ಯವಾದಗಳು. ನಾವು ನಿಮಗೆ ಹೇಗೆ ಸಹಾಯ ಮಾಡಬಹುದು?',
        'ml'    => 'നമസ്കാരം! വിളിച്ചതിന് നന്ദി. ഞങ്ങൾ നിങ്ങളെ എങ്ങനെ സഹായിക്കണം?',
        'ne'    => 'नमस्ते! फोन गर्नुभएकोमा धन्यवाद। भन्नुहोस्, हामी तपाईंलाई कसरी सहयोग गर्न सक्छौं?',
        'si'    => 'ආයුබෝවන්! ඇමතීමට ස්තූතියි. අපට ඔබට උදව් කළ හැක්කේ කෙසේද?',
        'ar'    => 'مرحباً! شكراً لاتصالك. كيف يمكننا مساعدتك؟',
        'fa'    => 'سلام! از تماس شما متشکریم. چطور می‌توانیم کمکتان کنیم؟',
        'tr'    => 'Merhaba! Aradığınız için teşekkürler. Size nasıl yardımcı olabiliriz?',
        'he'    => 'שלום! תודה שהתקשרת. איך נוכל לעזור?',
        'es'    => '¡Hola! Gracias por llamar. ¿En qué podemos ayudarle?',
        'fr'    => 'Bonjour ! Merci de votre appel. Comment pouvons-nous vous aider ?',
        'de'    => 'Hallo! Danke für Ihren Anruf. Wie können wir Ihnen helfen?',
        'it'    => 'Buongiorno! Grazie per aver chiamato. Come possiamo aiutarla?',
        'pt'    => 'Olá! Obrigado por ligar. Como podemos ajudar?',
        'nl'    => 'Hallo! Bedankt voor uw telefoontje. Waarmee kunnen we u helpen?',
        'pl'    => 'Dzień dobry! Dziękujemy za telefon. W czym możemy pomóc?',
        'ru'    => 'Здравствуйте! Спасибо за звонок. Чем мы можем помочь?',
        'uk'    => 'Доброго дня! Дякуємо за дзвінок. Чим ми можемо допомогти?',
        'ro'    => 'Bună ziua! Vă mulțumim că ați sunat. Cu ce vă putem ajuta?',
        'cs'    => 'Dobrý den! Děkujeme za zavolání. Jak vám můžeme pomoci?',
        'el'    => 'Γεια σας! Ευχαριστούμε που καλέσατε. Πώς μπορούμε να σας βοηθήσουμε;',
        'sv'    => 'Hej! Tack för att du ringer. Hur kan vi hjälpa dig?',
        'da'    => 'Hej! Tak fordi du ringer. Hvordan kan vi hjælpe dig?',
        'nb'    => 'Hei! Takk for at du ringer. Hvordan kan vi hjelpe deg?',
        'fi'    => 'Hei! Kiitos soitostasi. Miten voimme auttaa?',
        'hu'    => 'Jó napot! Köszönjük, hogy hívott. Miben segíthetünk?',
        'bg'    => 'Здравейте! Благодарим ви за обаждането. С какво можем да помогнем?',
        'zh'    => '您好！感谢您的来电。请问有什么可以帮您？',
        'ja'    => 'お電話ありがとうございます。ご用件をお伺いします。',
        'ko'    => '안녕하세요! 전화 주셔서 감사합니다. 무엇을 도와드릴까요?',
        'id'    => 'Halo! Terima kasih sudah menelepon. Ada yang bisa kami bantu?',
        'ms'    => 'Helo! Terima kasih kerana menelefon. Ada apa-apa yang boleh kami bantu?',
        'th'    => 'สวัสดี ขอบคุณที่โทรมา มีอะไรให้เราช่วยไหม',
        'vi'    => 'Xin chào! Cảm ơn bạn đã gọi. Chúng tôi có thể giúp gì cho bạn?',
        'fil'   => 'Kumusta! Salamat sa pagtawag. Paano po kami makakatulong?',
        'sw'    => 'Habari! Asante kwa kupiga simu. Tunaweza kukusaidia vipi?',
        'am'    => 'ሰላም! ስለደወሉ እናመሰግናለን። እንዴት ልንረዳዎ እንችላለን?',
    ];

    /**
     * The agent's own greeting; else, for an English agent, the project's
     * welcome message; else the default in the agent's language.
     */
    public static function for(Project $project, Session $session): string
    {
        $call = (array) data_get($session->metadata, 'call', []);

        if (! empty($call['greeting'])) {
            return (string) $call['greeting'];
        }

        $language = $call['language'] ?? 'en';
        $welcome  = trim((string) data_get($project->json_data, 'widget.welcome_message', ''));
        if ($language === 'en' && $welcome !== '') {
            return $welcome;
        }

        return self::DEFAULTS[$language] ?? self::DEFAULTS['en'];
    }
}
