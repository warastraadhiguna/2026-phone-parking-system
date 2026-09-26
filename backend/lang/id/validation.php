<?php

/*
| Indonesian validation messages for the rules used by the application.
| Missing keys fall back to English (APP_FALLBACK_LOCALE).
*/

return [
    'array' => ':Attribute harus berupa daftar.',
    'boolean' => ':Attribute harus bernilai benar atau salah.',
    'confirmed' => 'Konfirmasi :attribute tidak cocok.',
    'date' => ':Attribute bukan tanggal yang valid.',
    'email' => ':Attribute harus berupa alamat email yang valid.',
    'enum' => ':Attribute yang dipilih tidak valid.',
    'exists' => ':Attribute yang dipilih tidak valid.',
    'in' => ':Attribute yang dipilih tidak valid.',
    'integer' => ':Attribute harus berupa bilangan bulat.',
    'max' => [
        'array' => ':Attribute maksimal berisi :max item.',
        'numeric' => ':Attribute maksimal :max.',
        'string' => ':Attribute maksimal :max karakter.',
    ],
    'min' => [
        'array' => ':Attribute minimal berisi :min item.',
        'numeric' => ':Attribute minimal :min.',
        'string' => ':Attribute minimal :min karakter.',
    ],
    'numeric' => ':Attribute harus berupa angka.',
    'password' => [
        'letters' => ':Attribute harus mengandung minimal satu huruf.',
        'mixed' => ':Attribute harus mengandung huruf besar dan huruf kecil.',
        'numbers' => ':Attribute harus mengandung minimal satu angka.',
        'symbols' => ':Attribute harus mengandung minimal satu simbol.',
        'uncompromised' => ':Attribute ini pernah bocor di internet. Gunakan kata sandi lain.',
    ],
    'regex' => 'Format :attribute tidak valid.',
    'required' => ':Attribute wajib diisi.',
    'string' => ':Attribute harus berupa teks.',
    'unique' => ':Attribute sudah digunakan.',
    'uuid' => ':Attribute harus berupa UUID yang valid.',

    'custom' => [
        'username' => [
            'regex' => 'Username hanya boleh huruf kecil, angka, titik, garis bawah, atau tanda hubung (3–50 karakter).',
        ],
    ],

    'attributes' => [],
];
