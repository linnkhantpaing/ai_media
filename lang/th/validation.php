<?php

return [
    'required' => 'กรุณากรอก:attribute',
    'string' => ':attributeต้องเป็นข้อความ',
    'email' => 'กรุณากรอกอีเมลที่ถูกต้อง',
    'confirmed' => 'รหัสผ่านยืนยันไม่ตรงกัน',
    'boolean' => 'ค่า:attributeไม่ถูกต้อง',
    'in' => ':attributeที่เลือกไม่ถูกต้อง',
    'max' => [
        'string' => ':attributeต้องไม่เกิน :max ตัวอักษร',
    ],
    'min' => [
        'string' => ':attributeต้องมีอย่างน้อย :min ตัวอักษร',
    ],
    'password' => [
        'letters' => 'ต้องมีตัวอักษรอย่างน้อยหนึ่งตัว',
        'mixed' => 'ต้องมีทั้งตัวพิมพ์ใหญ่และตัวพิมพ์เล็ก',
        'numbers' => 'ต้องมีตัวเลขอย่างน้อยหนึ่งตัว',
        'symbols' => 'ต้องมีสัญลักษณ์อย่างน้อยหนึ่งตัว',
    ],
    'attributes' => [
        'email' => 'อีเมล',
        'password' => 'รหัสผ่าน',
        'password_confirmation' => 'รหัสผ่านยืนยัน',
        'locale' => 'ภาษา',
        'token' => 'โทเค็นรีเซ็ต',
        'name' => 'ชื่อ',
    ],
];
