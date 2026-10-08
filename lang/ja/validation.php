<?php

/*
 * Messages for the rules the API's requests use. A rule without one here
 * falls back to the framework's English (fallback_locale).
 *
 * :attribute is the query parameter's own name (NamesParametersAsIs), so
 * a message points at exactly what to fix.
 */
return [
    'after_or_equal' => ':attribute は :date 以降の日付にしてください。',
    'between' => [
        'numeric' => ':attribute は :min 以上 :max 以下にしてください。',
        'string' => ':attribute は :min 文字以上 :max 文字以下にしてください。',
    ],
    'date' => ':attribute は正しい日付にしてください。',
    'date_format' => ':attribute は :format の形式にしてください（例: 2026-10-01）。',
    'encoding' => ':attribute は :encoding で指定してください。',
    'enum' => ':attribute の値が正しくありません。',
    'in' => ':attribute の値が正しくありません。',
    'integer' => ':attribute は整数にしてください。',
    'max' => [
        'numeric' => ':attribute は :max 以下にしてください。',
        'string' => ':attribute は :max 文字以下にしてください。',
    ],
    'min' => [
        'numeric' => ':attribute は :min 以上にしてください。',
        'string' => ':attribute は :min 文字以上にしてください。',
    ],
    'numeric' => ':attribute は数値にしてください。',
    'regex' => ':attribute の形式が正しくありません。',
    'required' => ':attribute を指定してください。',
    'required_with' => ':values を指定するときは、:attribute も指定してください。',
    'string' => ':attribute は文字列にしてください。',
];
