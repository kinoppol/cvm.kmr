<?php

/*
 * ตัวรับหน้าเมื่อเปิด URL รากแบบไม่มี / ท้าย (เช่น http://host/web)
 *
 * ปกติ mod_rewrite จะพา request เข้า public/ ให้ แต่กับ URL ที่ตรงกับตัวโฟลเดอร์พอดีและไม่มี / ท้าย
 * Apache จะข้ามกฎ rewrite ใน .htaccess แล้วส่งต่อให้ mod_dir จัดการเอง — ซึ่ง DirectorySlash ถูกปิดไว้
 * (กันวน redirect กับโฟลเดอร์จริงอย่าง app/ vendor/) จึงไม่มีอะไรมาเสิร์ฟและกลายเป็น 403
 *
 * ไฟล์นี้จึงทำหน้าที่เป็น DirectoryIndex ของราก ส่งต่อให้ตัวแอปตามปกติ แล้ว middleware ใน bootstrap.php
 * จะ 301 ไปยัง URL ที่มี / ท้ายให้เอง
 */

declare(strict_types=1);

require __DIR__ . '/public/index.php';
