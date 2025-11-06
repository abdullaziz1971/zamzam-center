<?php
declare(strict_types=1);

// بيئة العمل
const ZAMZAM_ENV_HOST = 'dev-zamzam-center.com';

// الجذور المسموح بها للوكيل
const ZAMZAM_ALLOWED_ROOTS = [
  '/home/u794073177/domains/dev-zamzam-center.com/public_html',
  '/home/u794073177/domains/dev-zamzam-center.com/public_html/admin_v2',
  '/home/u794073177/domains/dev-zamzam-center.com/public_html/_admin_live',
];

// مجلد النسخ الاحتياطي
const ZAMZAM_BACKUP_DIR = '/home/u794073177/domains/dev-zamzam-center.com/public_html/admin_v2/backups';

// تفعيل الوكيل والمفتاح السري
const ZAMZAM_AGENT_ENABLED = true;
const ZAMZAM_SHARED_SECRET = 'Zamzam2025Sync';

