<?php
declare(strict_types=1);

// استدعاء ملف الإعداد العام للموقع
require_once __DIR__ . '/config.php';

// المفتاح الموحّد لجميع العمليات (الوكيل + الجسر)
const BRIDGE_SECRET = 'Zamzam2025Sync';

// تمكين الوكيل واستخدام نفس الإعدادات من config.php
if (!defined('ZAMZAM_AGENT_ENABLED')) define('ZAMZAM_AGENT_ENABLED', true);
if (!defined('ZAMZAM_SHARED_SECRET')) define('ZAMZAM_SHARED_SECRET', BRIDGE_SECRET);
