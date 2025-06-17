<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class SeedBilingualGameInstructions extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $gameInstructions = [
            'mines' => [
                'en' => 'Click tiles to reveal them. Each safe tile increases your multiplier. Cash out anytime to secure winnings, but hit a bomb and lose everything! Use the flag power-up (0.5 tokens) to reveal one guaranteed safe tile.',
                'am' => 'ሰሌዳዎችን ለማየት ጠቅ ያድርጉ። እያንዳንዱ ደህንነቱ የተጠበቀ ሰሌዳ አባዚዎን ይጨምራል። ለማሸነፍ በማንኛውም ጊዜ መውጣት ይችላሉ፣ ነገር ግን ቦምብ ከመታዎት ሁሉንም ያጣሉ! አንድን ደህንነቱ የተጠበቀ ሰሌዳ ለማግኘት የባንዲራ ሀይልን (0.5 ቶከን) ይጠቀሙ።'
            ],
            'crazy' => [
                'en' => 'Ethiopian Crazy Cards! Match cards by number or suit. Say "Qeregn" when you have one card left. If you forget and someone catches you, you draw two cards! Play all your cards first to win. Use special cards strategically: 8 skips next player, 5 can be played on any card, and Ace changes the suit.',
                'am' => 'የኢትዮጵያ ክሪዚ ካርድ! ካርዶችን በቁጥር ወይም በአይነት ያዛምዱ። አንድ ካርድ ሲቀርዎት "ቀረኝ" ይበሉ። ከዘነጉ እና ሌላ ሰው ከያዝዎት፣ ሁለት ካርድ ይሳባሉ! ለማሸነፍ ካርዶችዎን በመጀመሪያ ይጨርሱ። ልዩ ካርዶችን በስትራቴጂ ይጠቀሙ፡ 8 ቀጣዩን ተጫዋች ያዝለዋል፣ 5 በማንኛውም ካርድ ላይ መጫወት ይቻላል፣ እና ኤስ የካርዱን አይነት ይቀይራል።'
            ],
            'geo-sprint' => [
                'en' => 'Test your geography knowledge! Answer questions about Ethiopia, Africa, and the world. Quick answers earn bonus points. You have a time limit per question. Build streaks for consecutive correct answers!',
                'am' => 'የጂኦግራፊ እውቀትዎን ይፈትሹ! ስለ ኢትዮጵያ፣ አፍሪካ እና ዓለም ጥያቄዎችን ይመልሱ። ፈጣን መልሶች ተጨማሪ ነጥቦችን ያስገኛሉ። በእያንዳንዱ ጥያቄ ላይ የጊዜ ገደብ አለ። ተከታታይ ትክክለኛ መልሶችን በመስጠት ተጨማሪ ነጥብ ያግኙ!'
            ],
            'word-grid-blitz' => [
                'en' => 'Connect adjacent letters to form words. Words must be at least 3 letters long. Longer words score more points. Find as many words as possible before time runs out! Use shuffle strategically to find more words.',
                'am' => 'ቃላትን ለመፍጠር ተያያዥ ፊደላትን ያገናኙ። ቃላት ቢያንስ 3 ፊደላት መሆን አለባቸው። ረጅም ቃላት የበለጠ ነጥብ ያስገኛሉ። ጊዜው ከማለቁ በፊት በተቻለ መጠን ብዙ ቃላትን ያግኙ! ተጨማሪ ቃላትን ለማግኘት በስትራተጂ የመቀየር ዘዴን ይጠቀሙ።'
            ],
            'rapid-recall' => [
                'en' => 'Memorize sequences of colors, numbers, and symbols. Repeat the sequence exactly as shown. Each successful round adds one more item to remember. You have 3 lives - use them wisely!',
                'am' => 'የቀለሞችን፣ ቁጥሮችን እና ምልክቶችን ቅደም ተከተል ያስታውሱ። ቅደም ተከተሉን እንደታየው በትክክል ይድገሙ። እያንዳንዱ የተሳካ ዙር አንድ ተጨማሪ ነገር ያስታውሳል። 3 ሕይወት አለዎት - በጥበብ ይጠቀሙባቸው!'
            ],
            'pixel-reveal' => [
                'en' => 'Images start pixelated and become clearer over time. Guess quickly for maximum points. You have 5 guesses per image and 2 hints available. Complete as many images as you can in 2 minutes!',
                'am' => 'ምስሎች በፒክሴል ተሸፍነው ይጀምሩና በጊዜ ሂደት እየጠሩ ይሄዳሉ። ከፍተኛ ነጥብ ለማግኘት በፍጥነት ይገምቱ። በእያንዳንዱ ምስል 5 ግምቶች እና 2 ፍንጮች አሉዎት። በ2 ደቂቃዎች ውስጥ በተቻለ መጠን ብዙ ምስሎችን ይጨርሱ!'
            ],
            'number-merge-2048' => [
                'en' => 'Swipe to move all tiles. Merge matching numbers to create larger ones. Reach 2048 to win, but keep going for a higher score! Use power-ups strategically. You have 5 minutes to achieve the highest score possible.',
                'am' => 'ሁሉንም ሰሌዳዎች ለማንቀሳቀስ ይጥረጉ። ተመሳሳይ ቁጥሮችን በማዋሃድ ትልቅ ቁጥሮችን ይፍጠሩ። ለማሸነፍ 2048ን ይድረሱ፣ ነገር ግን ከፍተኛ ውጤት ለማግኘት ይቀጥሉ! ሀይል አሻሽጊዎችን በስትራተጂ ይጠቀሙ። ከፍተኛውን ውጤት ለማግኘት 5 ደቂቃ አለዎት።'
            ],
            'math-sprint-duel' => [
                'en' => 'Solve math problems as quickly as possible. You have 1 minute to solve as many as you can. Build streaks for bonus multipliers. Quick solutions earn time bonuses. Wrong answers break your streak!',
                'am' => 'የሂሳብ ችግሮችን በተቻለ ፍጥነት ይፍቱ። በተቻለ መጠን ብዙ ለመፍታት 1 ደቂቃ አለዎት። ለተጨማሪ አባዦች ተከታታይ ድሎችን ይገንቡ። ፈጣን መፍትሄዎች የጊዜ ጉርሻዎችን ያስገኛሉ። ስህተት መልሶች ተከታታይ ድልዎን ያቋርጣሉ!'
            ],
            'letter-leap' => [
                'en' => 'Click letters to select them in order. Form words with 3 or more letters. Special letters give bonus points. Create word combos for multipliers. Find as many words as possible in 2 minutes. Longer words score higher!',
                'am' => 'ፊደላትን በቅደም ተከተል ለመምረጥ ጠቅ ያድርጉ። በ3 ወይም ከዚያ በላይ ፊደላት ቃላትን ይፍጠሩ። ልዩ ፊደላት ተጨማሪ ነጥብ ይሰጣሉ። ለአባዦች የቃል ኮምቦዎችን ይፍጠሩ። በ2 ደቂቃዎች ውስጥ በተቻለ መጠን ብዙ ቃላትን ያግኙ። ረጅም ቃላት ከፍተኛ ነጥብ ያስገኛሉ!'
            ],
            'codebreaker' => [
                'en' => 'Crack the 4-digit code using logic and deduction. Red numbers mean right digit in right position. White numbers mean right digit in wrong position. X means no match. Use hints strategically!',
                'am' => 'ሎጂክና ዲዳክሽንን በመጠቀም የ4 አሃዝ ኮድን ይፍቱ። ቀይ ቁጥሮች ትክክለኛ አሃዝ በትክክለኛ ቦታ ማለት ነው። ነጭ ቁጥሮች ትክክለኛ አሃዝ በተሳሳተ ቦታ ማለት ነው። X ምንም ተመሳሳይ እንደሌለ ያሳያል። ፍንጮችን በስትራተጂ ይጠቀሙ!'
            ],
            'sum-chaser' => [
                'en' => 'Three random digits (0-9) are revealed one by one. Predict if each new digit will push the sum over or under the threshold. Starting threshold is 13.5. Correct predictions increase your multiplier. Cash out anytime!',
                'am' => 'ሶስት አራዳ አሃዞች (0-9) አንድ በአንድ ይገለጻሉ። እያንዳንዱ አዲስ አሃዝ ድምሩን ከመድረሻው በላይ ወይም በታች እንደሚያደርገው ይተንብዩ። የመጀመሪያው መድረሻ 13.5 ነው። ትክክለኛ ትንበያዎች አባዚዎን ይጨምራሉ። በማንኛውም ጊዜ መውጣት ይችላሉ!'
            ]
        ];

        foreach ($gameInstructions as $slug => $instructions) {
            DB::table('games')
                ->where('slug', $slug)
                ->update(['instructions' => json_encode($instructions)]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('games')->update(['instructions' => null]);
    }
} 