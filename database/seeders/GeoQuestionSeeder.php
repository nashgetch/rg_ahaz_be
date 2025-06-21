<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\GeoQuestion;

class GeoQuestionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Clear existing questions
        GeoQuestion::truncate();
        
        $questions = [
            // Ethiopia questions
            [
                'question_id' => 'eth-1',
                'question' => 'What is the capital city of Ethiopia?',
                'options' => ['Addis Ababa', 'Dire Dawa', 'Bahir Dar', 'Mekelle'],
                'correct_answer' => 0,
                'category' => 'ethiopia',
                'difficulty' => 'easy',
                'explanation' => 'Addis Ababa is the capital and largest city of Ethiopia, and also serves as the seat of the African Union.',
                'points' => 100,
                'is_active' => true,
            ],
            [
                'question_id' => 'eth-2',
                'question' => 'Which river is known as the Blue Nile in Ethiopia?',
                'options' => ['Awash River', 'Abay River', 'Tekeze River', 'Wabe Shebelle'],
                'correct_answer' => 1,
                'category' => 'ethiopia',
                'difficulty' => 'medium',
                'explanation' => 'The Abay River is the Ethiopian name for the Blue Nile, which originates from Lake Tana.',
                'points' => 120,
                'is_active' => true,
            ],
            [
                'question_id' => 'eth-3',
                'question' => 'Lake Tana is the source of which famous river?',
                'options' => ['White Nile', 'Blue Nile', 'Congo River', 'Niger River'],
                'correct_answer' => 1,
                'category' => 'ethiopia',
                'difficulty' => 'easy',
                'explanation' => 'Lake Tana is the largest lake in Ethiopia and the source of the Blue Nile.',
                'points' => 100,
                'is_active' => true,
            ],
            [
                'question_id' => 'eth-4',
                'question' => 'What is the highest mountain in Ethiopia?',
                'options' => ['Mount Entoto', 'Ras Dejen', 'Mount Zuqualla', 'Mount Choke'],
                'correct_answer' => 1,
                'category' => 'ethiopia',
                'difficulty' => 'medium',
                'explanation' => 'Ras Dejen (also known as Ras Dashen) is the highest peak in Ethiopia at 4,550 meters.',
                'points' => 120,
                'is_active' => true,
            ],
            [
                'question_id' => 'eth-5',
                'question' => 'Which ancient Ethiopian city is famous for its rock-hewn churches?',
                'options' => ['Axum', 'Lalibela', 'Gondar', 'Harar'],
                'correct_answer' => 1,
                'category' => 'ethiopia',
                'difficulty' => 'easy',
                'explanation' => 'Lalibela is famous for its 11 medieval monolithic rock-hewn churches.',
                'points' => 100,
                'is_active' => true,
            ],

            // Africa questions
            [
                'question_id' => 'afr-1',
                'question' => 'Which is the longest river in Africa?',
                'options' => ['Congo River', 'Niger River', 'Nile River', 'Zambezi River'],
                'correct_answer' => 2,
                'category' => 'africa',
                'difficulty' => 'easy',
                'explanation' => 'The Nile River is the longest river in Africa and the world at about 6,650 km.',
                'points' => 100,
                'is_active' => true,
            ],
            [
                'question_id' => 'afr-2',
                'question' => 'What is the largest desert in Africa?',
                'options' => ['Kalahari Desert', 'Sahara Desert', 'Namib Desert', 'Karoo Desert'],
                'correct_answer' => 1,
                'category' => 'africa',
                'difficulty' => 'easy',
                'explanation' => 'The Sahara Desert is the largest hot desert in the world, covering much of North Africa.',
                'points' => 100,
                'is_active' => true,
            ],
            [
                'question_id' => 'afr-3',
                'question' => 'Which African country has three capital cities?',
                'options' => ['Nigeria', 'South Africa', 'Kenya', 'Morocco'],
                'correct_answer' => 1,
                'category' => 'africa',
                'difficulty' => 'medium',
                'explanation' => 'South Africa has three capitals: Cape Town (legislative), Pretoria (executive), and Bloemfontein (judicial).',
                'points' => 120,
                'is_active' => true,
            ],
            [
                'question_id' => 'afr-4',
                'question' => 'Lake Victoria is shared by which three countries?',
                'options' => ['Kenya, Tanzania, Rwanda', 'Uganda, Kenya, Tanzania', 'Ethiopia, Kenya, Sudan', 'Tanzania, Malawi, Mozambique'],
                'correct_answer' => 1,
                'category' => 'africa',
                'difficulty' => 'medium',
                'explanation' => 'Lake Victoria, the largest lake in Africa, is shared by Uganda, Kenya, and Tanzania.',
                'points' => 120,
                'is_active' => true,
            ],
            [
                'question_id' => 'afr-5',
                'question' => 'Which mountain range runs along the northwest coast of Africa?',
                'options' => ['Atlas Mountains', 'Drakensberg Mountains', 'Ahaggar Mountains', 'Ethiopian Highlands'],
                'correct_answer' => 0,
                'category' => 'africa',
                'difficulty' => 'medium',
                'explanation' => 'The Atlas Mountains stretch across Morocco, Algeria, and Tunisia.',
                'points' => 120,
                'is_active' => true,
            ],

            // World questions
            [
                'question_id' => 'wld-1',
                'question' => 'Which is the smallest country in the world?',
                'options' => ['Monaco', 'Vatican City', 'San Marino', 'Liechtenstein'],
                'correct_answer' => 1,
                'category' => 'world',
                'difficulty' => 'easy',
                'explanation' => 'Vatican City is the smallest sovereign state in the world at just 0.17 square miles.',
                'points' => 100,
                'is_active' => true,
            ],
            [
                'question_id' => 'wld-2',
                'question' => 'Which strait connects the Atlantic and Pacific oceans?',
                'options' => ['Strait of Gibraltar', 'Bering Strait', 'Strait of Magellan', 'Drake Passage'],
                'correct_answer' => 2,
                'category' => 'world',
                'difficulty' => 'medium',
                'explanation' => 'The Strait of Magellan connects the Atlantic and Pacific oceans at the southern tip of South America.',
                'points' => 120,
                'is_active' => true,
            ],
            [
                'question_id' => 'wld-3',
                'question' => 'What is the highest mountain in the world?',
                'options' => ['K2', 'Mount Everest', 'Kangchenjunga', 'Lhotse'],
                'correct_answer' => 1,
                'category' => 'world',
                'difficulty' => 'easy',
                'explanation' => 'Mount Everest is the highest mountain in the world at 8,848.86 meters above sea level.',
                'points' => 100,
                'is_active' => true,
            ],
            [
                'question_id' => 'wld-4',
                'question' => 'Which country has the most time zones?',
                'options' => ['Russia', 'USA', 'China', 'France'],
                'correct_answer' => 3,
                'category' => 'world',
                'difficulty' => 'hard',
                'explanation' => 'France has 12 time zones due to its overseas territories, more than any other country.',
                'points' => 140,
                'is_active' => true,
            ],
            [
                'question_id' => 'wld-5',
                'question' => 'Which is the deepest ocean trench?',
                'options' => ['Puerto Rico Trench', 'Java Trench', 'Mariana Trench', 'Peru-Chile Trench'],
                'correct_answer' => 2,
                'category' => 'world',
                'difficulty' => 'medium',
                'explanation' => 'The Mariana Trench in the Pacific Ocean is the deepest part of the world\'s oceans.',
                'points' => 120,
                'is_active' => true,
            ],
        ];

        foreach ($questions as $question) {
            GeoQuestion::create($question);
        }

        $this->command->info('Successfully seeded ' . count($questions) . ' geography questions.');
    }
} 