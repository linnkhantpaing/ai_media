<?php

return [
    'required' => 'The :attribute field is required.',
    'string' => 'The :attribute must be text.',
    'email' => 'Please enter a valid email address.',
    'confirmed' => 'The password confirmation does not match.',
    'boolean' => 'The :attribute must be true or false.',
    'in' => 'The selected :attribute is invalid.',
    'max' => [
        'string' => 'The :attribute must not exceed :max characters.',
    ],
    'min' => [
        'string' => 'The :attribute must have at least :min characters.',
    ],
    'password' => [
        'letters' => 'Include at least one letter.',
        'mixed' => 'Include uppercase and lowercase letters.',
        'numbers' => 'Include at least one number.',
        'symbols' => 'Include at least one symbol.',
    ],
    'attributes' => [
        'email' => 'email',
        'password' => 'password',
        'password_confirmation' => 'password confirmation',
        'locale' => 'language',
        'token' => 'reset token',
        'name' => 'name',
    ],
];
