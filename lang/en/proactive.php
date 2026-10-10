<?php

/*
 * Fixed proactive Concierge messages (SPEC-073). Never AI-written: every
 * placeholder is filled from the hotel's own records. Each offer ends with an
 * invitation to reply, which continues the conversation with the Concierge.
 */
return [

    'first_morning' => [
        'with_recommendation' => 'Good morning :guest! We hope your first night at :hotel was restful. If you are looking for something to do, :activity could be a lovely choice: :description Reply here if you would like to know more.',
        'without_description' => 'Good morning :guest! We hope your first night at :hotel was restful. If you are looking for something to do, :activity could be a lovely choice. Reply here if you would like to know more.',
    ],

    'mid_stay' => [
        'with_recommendation' => 'Hello :guest, we hope you are enjoying your stay at :hotel. For the days ahead, you might enjoy :activity: :description Reply here if you would like to know more.',
        'without_description' => 'Hello :guest, we hope you are enjoying your stay at :hotel. For the days ahead, you might enjoy :activity. Reply here if you would like to know more.',
    ],

    'recommendation_approved' => [
        'default' => 'Hello :guest, the team at :hotel thought you might enjoy :activity: :description Reply here if you would like to know more.',
        'without_description' => 'Hello :guest, the team at :hotel thought you might enjoy :activity. Reply here if you would like to know more.',
    ],

    'upcoming_activity' => [
        'with_time' => 'Hello :guest, a reminder from :hotel: your :activity is on :date at :time. Reply here if you have any questions.',
    ],

    'opt_out' => [
        'confirmed' => 'Understood. You will not receive any more messages or offers from us unless you ask. You can still message us any time, and reply START to receive them again.',
        'resumed' => 'Thank you. You may receive occasional messages and suggestions from us again. Reply STOP at any time to stop them.',
    ],

];
