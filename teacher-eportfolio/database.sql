-- =====================================================================
-- قاعدة بيانات ملف الإنجاز الإلكتروني — جدول مستقل لكل قسم في الموقع
-- الاستيراد من phpMyAdmin: اختر قاعدة البيانات ← «استيراد» (Import) ← اختر هذا الملف ← «تنفيذ»
-- (إن لم تستورده، يُنشئه api.php تلقائياً عند أول تشغيل)
--
-- الجداول:
--   ep_settings        إعدادات الموقع (الاسم، العام الدراسي، الألوان، الخط، ...)
--   ep_profile         السيرة الذاتية (صف واحد)
--   ep_cards           بطاقات قسم الأساسيات السبع (العنوان، الوصف، الصورة، الإظهار)
--   ep_courses         النمو المهني (الدورات والبرامج التدريبية)
--   ep_certificates    شهادات الشكر وشهادات الدورات
--   ep_folders         مجلدات الأداء والمبادرات
--   ep_folder_points   العناصر الفرعية لكل مجلد (مع حالة الإنجاز)
--   ep_folder_items    الشواهد المرفوعة داخل كل مجلد (روابط، صور، Word، PDF)
--   ep_login_attempts  حماية تسجيل الدخول من التخمين
-- =====================================================================

SET NAMES utf8mb4;

-- ---------- إعدادات الموقع ----------
CREATE TABLE IF NOT EXISTS ep_settings (
  setting_key   VARCHAR(64)  NOT NULL PRIMARY KEY COMMENT 'اسم الإعداد',
  setting_value TEXT         NOT NULL COMMENT 'قيمة الإعداد',
  updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='إعدادات الموقع';

-- ---------- السيرة الذاتية ----------
CREATE TABLE IF NOT EXISTS ep_profile (
  id                    TINYINT      NOT NULL PRIMARY KEY DEFAULT 1,
  full_name             VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'الاسم الكامل',
  national_id           VARCHAR(20)  NOT NULL DEFAULT '' COMMENT 'رقم السجل المدني',
  birth_date            DATE         NULL     COMMENT 'تاريخ الميلاد',
  nationality           VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'الجنسية',
  phone                 VARCHAR(30)  NOT NULL DEFAULT '' COMMENT 'الجوال',
  email                 VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'البريد الإلكتروني',
  job_title             VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'المسمى الوظيفي',
  job_rank              VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'الرتبة',
  employee_id           VARCHAR(50)  NOT NULL DEFAULT '' COMMENT 'الرقم الوظيفي',
  school_name           VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'المدرسة',
  education_department  VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'إدارة التعليم',
  hire_date             DATE         NULL     COMMENT 'تاريخ التعيين',
  experience_years      INT          NULL     COMMENT 'سنوات الخبرة',
  subjects              VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'المواد التي تُدرَّس',
  teaching_load         INT          NULL     COMMENT 'النصاب (عدد الحصص)',
  degree                VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'المؤهل العلمي',
  major                 VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'التخصص',
  university            VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'الجامعة',
  graduation_year       VARCHAR(20)  NOT NULL DEFAULT '' COMMENT 'سنة التخرج',
  professional_license  VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'الرخصة المهنية',
  avatar_url            VARCHAR(500) NULL     COMMENT 'الصورة الشخصية',
  updated_at            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='السيرة الذاتية';

-- ---------- بطاقات الأساسيات ----------
CREATE TABLE IF NOT EXISTS ep_cards (
  card_key    VARCHAR(40)  NOT NULL PRIMARY KEY,
  title       VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'عنوان البطاقة',
  description TEXT         NOT NULL COMMENT 'وصف البطاقة',
  image_url   VARCHAR(500) NULL     COMMENT 'صورة البطاقة',
  is_hidden   TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = مخفية',
  sort_order  INT          NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='بطاقات قسم الأساسيات';

-- ---------- النمو المهني ----------
CREATE TABLE IF NOT EXISTS ep_courses (
  id              INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
  title           VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'اسم البرنامج / الدورة',
  provider        VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'الجهة المنفذة',
  course_date     DATE         NULL     COMMENT 'التاريخ',
  hours           DECIMAL(7,1) NULL     COMMENT 'عدد الساعات',
  attendance_mode VARCHAR(30)  NOT NULL DEFAULT 'حضوري' COMMENT 'نوع الحضور',
  has_certificate TINYINT(1)   NOT NULL DEFAULT 1 COMMENT 'يوجد شهادة',
  sort_order      INT          NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='النمو المهني';

-- ---------- الشهادات ----------
CREATE TABLE IF NOT EXISTS ep_certificates (
  id         INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
  category   ENUM('thanks','course') NOT NULL DEFAULT 'thanks' COMMENT 'thanks = شكر وتقدير، course = دورة',
  title      VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'اسم الشهادة',
  description TEXT        NULL     COMMENT 'وصف الشهادة',
  image_url  VARCHAR(500) NOT NULL COMMENT 'صورة الشهادة',
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sort_order INT          NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='شهادات الشكر والدورات';

-- ---------- مجلدات الأداء ----------
CREATE TABLE IF NOT EXISTS ep_folders (
  id         VARCHAR(40)  NOT NULL PRIMARY KEY,
  title      VARCHAR(255) NOT NULL COMMENT 'اسم المجلد',
  icon       VARCHAR(50)  NOT NULL DEFAULT 'fa-folder' COMMENT 'أيقونة Font Awesome',
  notes      TEXT         NULL     COMMENT 'ملاحظات ووصف الشواهد',
  is_hidden  TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = مخفي',
  sort_order INT          NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='مجلدات الأداء والمبادرات';

CREATE TABLE IF NOT EXISTS ep_folder_points (
  id         INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
  folder_id  VARCHAR(40)  NOT NULL,
  point_text VARCHAR(500) NOT NULL COMMENT 'نص العنصر',
  is_done    TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = مكتمل',
  sort_order INT          NOT NULL DEFAULT 0,
  KEY idx_folder (folder_id),
  CONSTRAINT fk_points_folder FOREIGN KEY (folder_id) REFERENCES ep_folders(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='عناصر المجلدات';

CREATE TABLE IF NOT EXISTS ep_folder_items (
  id         INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
  folder_id  VARCHAR(40)  NOT NULL,
  item_type  ENUM('link','image','word','pdf') NOT NULL COMMENT 'نوع الشاهد',
  title      VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'اسم الشاهد',
  description TEXT        NULL     COMMENT 'وصف الشاهد',
  url        TEXT         NOT NULL COMMENT 'الرابط أو مسار الملف',
  file_size  INT          NULL     COMMENT 'الحجم بالبايت',
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sort_order INT          NOT NULL DEFAULT 0,
  KEY idx_folder (folder_id),
  CONSTRAINT fk_items_folder FOREIGN KEY (folder_id) REFERENCES ep_folders(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='الشواهد المرفوعة في المجلدات';

-- ---------- حماية تسجيل الدخول ----------
CREATE TABLE IF NOT EXISTS ep_login_attempts (
  ip       VARCHAR(45) NOT NULL PRIMARY KEY,
  attempts INT         NOT NULL DEFAULT 0,
  last_at  INT         NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- البيانات الافتراضية
-- =====================================================================

-- رقم إصدار هيكل قاعدة البيانات (يُستخدم للترقية التلقائية)
INSERT IGNORE INTO ep_settings (setting_key, setting_value) VALUES ('_schema', '2');

INSERT IGNORE INTO ep_profile (id) VALUES (1);

INSERT IGNORE INTO ep_cards (card_key, title, description, sort_order) VALUES
('cv',            'السيرة الذاتية',               'البيانات الشخصية والوظيفية والمؤهلات العلمية في جدول منسق جاهز للتعبئة والطباعة.', 1),
('ksa-goals',     'أهداف التعليم في المملكة',     'الأهداف العامة لسياسة التعليم في المملكة العربية السعودية وفق رؤية 2030.', 2),
('science-goals', 'أهداف تدريس العلوم العامة',    'تنمية التفكير العلمي ومهارات الاستقصاء وربط العلم بالحياة والبيئة.', 3),
('ethics',        'ميثاق أخلاقيات المهنة',        'القيم والمبادئ المهنية التي يلتزم بها المعلم تجاه طلابه ومجتمعه ومهنته.', 4),
('vision',        'الرؤية والرسالة',              'رؤيتي التربوية ورسالتي التعليمية التي أسعى لتحقيقها مع طلابي.', 5),
('growth',        'النمو المهني',                 'سجّل الدورات والبرامج التدريبية وورش العمل مع الجهة المنفذة والتاريخ وعدد الساعات.', 6),
('certs',         'شهادات الشكر وشهادات الدورات', 'معرض تفاعلي لشهاداتك. اختر التصنيف ثم ارفع الصور أو اسحبها وأفلتها.', 7);

INSERT IGNORE INTO ep_folders (id, title, icon, sort_order) VALUES
('duties',      'أداء الواجبات الوظيفية',                  'fa-briefcase',       1),
('plan',        'إعداد وتنفيذ خطة التعلم',                 'fa-clipboard-list',  2),
('class',       'الإدارة الصفية',                          'fa-users-rectangle', 3),
('community',   'التفاعل مع المجتمع المحلي',               'fa-people-group',    4),
('parents',     'التفاعل مع أولياء الأمور',                'fa-handshake',       5),
('strategies',  'التنويع في استراتيجيات التدريس',          'fa-shapes',          6),
('initiatives', 'المبادرات في المدرسة',                    'fa-lightbulb',       7),
('improve',     'تحسين نتائج المتعلمين',                   'fa-arrow-trend-up',  8),
('analysis',    'تحليل نتائج المتعلمين وتشخيص مستوياتهم',  'fa-chart-pie',       9),
('assessment',  'تنويع أساليب التقويم',                    'fa-list-check',      10),
('environment', 'تهيئة البيئة التعليمية',                  'fa-chalkboard',      11),
('tech',        'توظيف تقنيات ووسائل التعلم المناسبة',     'fa-laptop-code',     12);

-- العناصر الفرعية الافتراضية (تُضاف فقط إذا كان الجدول فارغاً)
INSERT INTO ep_folder_points (folder_id, point_text, sort_order)
SELECT * FROM (
  SELECT 'duties' AS f, 'التقيد بالدوام الرسمي وأنظمة العمل' AS t, 1 AS o UNION ALL
  SELECT 'duties', 'تأدية الحصص الدراسية وفق الجدول المعتمد', 2 UNION ALL
  SELECT 'duties', 'المشاركة في الإشراف والمناوبة اليومية', 3 UNION ALL
  SELECT 'duties', 'المشاركة في اللجان والاجتماعات المدرسية', 4 UNION ALL
  SELECT 'duties', 'تنفيذ المهام والأعمال المكلّف بها', 5 UNION ALL
  SELECT 'plan', 'توزيع المنهج على الفصل الدراسي', 1 UNION ALL
  SELECT 'plan', 'إعداد الدروس (التحضير) وفق الأهداف', 2 UNION ALL
  SELECT 'plan', 'تنفيذ الدروس بحسب الخطة الزمنية', 3 UNION ALL
  SELECT 'plan', 'إعداد أوراق العمل والأنشطة الصفية', 4 UNION ALL
  SELECT 'plan', 'الخطط العلاجية والإثرائية', 5 UNION ALL
  SELECT 'class', 'قواعد وتعليمات الصف المتفق عليها', 1 UNION ALL
  SELECT 'class', 'أساليب التعزيز والتحفيز (لوحة النجوم، التكريم)', 2 UNION ALL
  SELECT 'class', 'إدارة وقت الحصة بفاعلية', 3 UNION ALL
  SELECT 'class', 'سجل متابعة الحضور والسلوك', 4 UNION ALL
  SELECT 'class', 'توزيع الأدوار والمهام على الطلاب', 5 UNION ALL
  SELECT 'community', 'المشاركة في المناسبات الوطنية والمجتمعية', 1 UNION ALL
  SELECT 'community', 'الشراكات مع الجهات الحكومية والخاصة', 2 UNION ALL
  SELECT 'community', 'الزيارات والرحلات الميدانية', 3 UNION ALL
  SELECT 'community', 'الأعمال التطوعية', 4 UNION ALL
  SELECT 'community', 'البرامج والحملات التوعوية', 5 UNION ALL
  SELECT 'parents', 'سجل التواصل مع أولياء الأمور', 1 UNION ALL
  SELECT 'parents', 'تقارير دورية عن مستوى الطلاب', 2 UNION ALL
  SELECT 'parents', 'حضور اجتماعات الجمعية العمومية', 3 UNION ALL
  SELECT 'parents', 'رسائل وإشعارات عبر منصة مدرستي', 4 UNION ALL
  SELECT 'parents', 'استبانات رضا أولياء الأمور', 5 UNION ALL
  SELECT 'strategies', 'التعلم التعاوني', 1 UNION ALL
  SELECT 'strategies', 'العصف الذهني', 2 UNION ALL
  SELECT 'strategies', 'التعلم بالاستقصاء والتجريب', 3 UNION ALL
  SELECT 'strategies', 'حل المشكلات', 4 UNION ALL
  SELECT 'strategies', 'الخرائط الذهنية والمفاهيمية', 5 UNION ALL
  SELECT 'strategies', 'التعلم باللعب', 6 UNION ALL
  SELECT 'initiatives', 'المبادرات المقدمة (الفكرة والأهداف)', 1 UNION ALL
  SELECT 'initiatives', 'خطة التنفيذ والجدول الزمني', 2 UNION ALL
  SELECT 'initiatives', 'صور وشواهد التنفيذ', 3 UNION ALL
  SELECT 'initiatives', 'المسابقات والأنشطة العلمية', 4 UNION ALL
  SELECT 'initiatives', 'أثر المبادرة ونتائجها', 5 UNION ALL
  SELECT 'improve', 'الخطط العلاجية للطلاب المتعثرين', 1 UNION ALL
  SELECT 'improve', 'البرامج الإثرائية للمتفوقين', 2 UNION ALL
  SELECT 'improve', 'متابعة تقدم الطلاب', 3 UNION ALL
  SELECT 'improve', 'تكريم الطلاب المتميزين', 4 UNION ALL
  SELECT 'improve', 'مقارنة النتائج قبل وبعد التدخل', 5 UNION ALL
  SELECT 'analysis', 'الاختبارات التشخيصية (القبلية)', 1 UNION ALL
  SELECT 'analysis', 'تحليل نتائج الاختبارات', 2 UNION ALL
  SELECT 'analysis', 'تصنيف الطلاب حسب المستويات', 3 UNION ALL
  SELECT 'analysis', 'خطط مبنية على نتائج التحليل', 4 UNION ALL
  SELECT 'assessment', 'التقويم القبلي والتكويني والختامي', 1 UNION ALL
  SELECT 'assessment', 'الاختبارات القصيرة والمراجعات', 2 UNION ALL
  SELECT 'assessment', 'سلالم التقدير وقوائم الرصد', 3 UNION ALL
  SELECT 'assessment', 'ملفات إنجاز الطلاب', 4 UNION ALL
  SELECT 'assessment', 'التقويم الذاتي وتقويم الأقران', 5 UNION ALL
  SELECT 'environment', 'تنظيم الفصل وترتيب المقاعد', 1 UNION ALL
  SELECT 'environment', 'الوسائل والمعلقات التعليمية', 2 UNION ALL
  SELECT 'environment', 'مراعاة الفروق الفردية', 3 UNION ALL
  SELECT 'environment', 'إجراءات الأمن والسلامة في المعمل', 4 UNION ALL
  SELECT 'environment', 'بيئة صفية محفزة وآمنة', 5 UNION ALL
  SELECT 'tech', 'توظيف منصة مدرستي', 1 UNION ALL
  SELECT 'tech', 'العروض التقديمية والمقاطع التعليمية', 2 UNION ALL
  SELECT 'tech', 'المعامل الافتراضية والمحاكاة', 3 UNION ALL
  SELECT 'tech', 'السبورة التفاعلية', 4 UNION ALL
  SELECT 'tech', 'التطبيقات والمصادر الرقمية', 5
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM ep_folder_points);
