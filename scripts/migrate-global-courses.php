<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../app/autoload.php';
use App\Core\Database as DB;

// Preview never writes. No legacy memberships or subjects are inferred by this migration.
if(!in_array('--apply',$argv,true)){
    $queries=[
        'active_students_without_batch'=>"SELECT COUNT(*) FROM users u JOIN roles r ON r.id=u.role_id WHERE r.code='student' AND u.status='active' AND u.deleted_at IS NULL AND u.batch_id IS NULL",
        'unique_batch_candidates'=>"SELECT COUNT(*) FROM (SELECT student_id FROM student_subjects GROUP BY student_id HAVING COUNT(DISTINCT batch_id)=1 AND SUM(batch_id IS NULL)=0) x",
        'union_access_widening_pairs'=>"SELECT COUNT(*) FROM (SELECT DISTINCT a.student_id,b.subject_id FROM student_subjects a JOIN student_subjects b ON b.batch_id=a.batch_id WHERE a.status IN ('active','completed') AND b.status IN ('active','completed') AND NOT EXISTS(SELECT 1 FROM student_subjects own WHERE own.student_id=a.student_id AND own.subject_id=b.subject_id AND own.status IN ('active','completed'))) x",
    ];
    $preview=['mode'=>'read-only preview','automatic_backfill'=>false];
    foreach($queries as $key=>$sql)$preview[$key]=(int)DB::val($sql);
    $preview['student_batch_candidates_review_required']=DB::all("SELECT ss.student_id,MIN(ss.batch_id) AS batch_id,u.centre_id FROM student_subjects ss JOIN users u ON u.id=ss.student_id JOIN roles r ON r.id=u.role_id AND r.code='student' JOIN batches b ON b.id=ss.batch_id WHERE u.batch_id IS NULL AND u.deleted_at IS NULL GROUP BY ss.student_id,u.centre_id HAVING COUNT(DISTINCT ss.batch_id)=1 AND SUM(ss.batch_id IS NULL)=0 AND SUM(NOT(b.centre_id <=> u.centre_id))=0 ORDER BY ss.student_id");
    echo json_encode($preview,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;exit;
}
if(!DB::val("SELECT setting_value FROM app_settings WHERE setting_key='phase3_registration_migrated'")){throw new RuntimeException('Run the existing centre/registration migrations first.');}
if(!DB::val("SELECT GET_LOCK('lms_global_courses_schema',20)")){throw new RuntimeException('Global course migration is already running.');}
try{
    $version=DB::val("SELECT setting_value FROM app_settings WHERE setting_key='global_courses_schema_version'");
    if($version!==null&&$version!=='1')throw new RuntimeException('Unexpected global course schema version; manual inspection required.');
    $audit="assigned_by BIGINT UNSIGNED NULL, assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, is_active TINYINT(1) NOT NULL DEFAULT 1";
    $ddl=[
        'courses'=>"id INT UNSIGNED NOT NULL AUTO_INCREMENT, code VARCHAR(40) NOT NULL, name VARCHAR(160) NOT NULL, description TEXT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1, created_by BIGINT UNSIGNED NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY(id), UNIQUE KEY uq_courses_code(code), FOREIGN KEY(created_by) REFERENCES users(id)",
        'course_subjects'=>"course_id INT UNSIGNED NOT NULL, subject_id INT UNSIGNED NOT NULL, $audit, PRIMARY KEY(course_id,subject_id), KEY idx_course_subject_reverse(subject_id), FOREIGN KEY(course_id) REFERENCES courses(id), FOREIGN KEY(subject_id) REFERENCES subjects(id), FOREIGN KEY(assigned_by) REFERENCES users(id)",
        'centre_courses'=>"centre_id INT UNSIGNED NOT NULL, course_id INT UNSIGNED NOT NULL, $audit, PRIMARY KEY(centre_id,course_id), KEY idx_centre_course_reverse(course_id), FOREIGN KEY(centre_id) REFERENCES centres(id), FOREIGN KEY(course_id) REFERENCES courses(id), FOREIGN KEY(assigned_by) REFERENCES users(id)",
        'batch_courses'=>"batch_id INT UNSIGNED NOT NULL, course_id INT UNSIGNED NOT NULL, $audit, PRIMARY KEY(batch_id,course_id), KEY idx_batch_course_reverse(course_id), FOREIGN KEY(batch_id) REFERENCES batches(id), FOREIGN KEY(course_id) REFERENCES courses(id), FOREIGN KEY(assigned_by) REFERENCES users(id)",
        'batch_subjects'=>"batch_id INT UNSIGNED NOT NULL, course_id INT UNSIGNED NOT NULL, subject_id INT UNSIGNED NOT NULL, $audit, PRIMARY KEY(batch_id,course_id,subject_id), KEY idx_batch_subject_course(course_id,subject_id), KEY idx_batch_subject_reverse(subject_id), FOREIGN KEY(batch_id,course_id) REFERENCES batch_courses(batch_id,course_id), FOREIGN KEY(course_id,subject_id) REFERENCES course_subjects(course_id,subject_id), FOREIGN KEY(assigned_by) REFERENCES users(id)",
        'teacher_batch_subjects'=>"teacher_id BIGINT UNSIGNED NOT NULL, batch_id INT UNSIGNED NOT NULL, course_id INT UNSIGNED NOT NULL, subject_id INT UNSIGNED NOT NULL, $audit, PRIMARY KEY(teacher_id,batch_id,course_id,subject_id), KEY idx_teacher_batch_mapping(batch_id,course_id,subject_id), FOREIGN KEY(teacher_id) REFERENCES users(id), FOREIGN KEY(batch_id,course_id,subject_id) REFERENCES batch_subjects(batch_id,course_id,subject_id), FOREIGN KEY(assigned_by) REFERENCES users(id)",
    ];
    foreach($ddl as $table=>$columns){DB::run("CREATE TABLE IF NOT EXISTS $table ($columns) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");}
    $keys=['courses'=>['id'],'course_subjects'=>['course_id','subject_id'],'centre_courses'=>['centre_id','course_id'],'batch_courses'=>['batch_id','course_id'],'batch_subjects'=>['batch_id','course_id','subject_id'],'teacher_batch_subjects'=>['teacher_id','batch_id','course_id','subject_id']];
    foreach($keys as $table=>$expected){
        $actual=array_column(DB::all("SHOW INDEX FROM $table WHERE Key_name='PRIMARY'"),'Column_name');
        $columns=array_column(DB::all("SHOW COLUMNS FROM $table"),'Type','Field');
        foreach(array_merge($expected,[$table==='courses'?'created_by':'assigned_by']) as $column){
            $kind=in_array($column,['teacher_id','assigned_by','created_by'],true)?'bigint':'int';
            if(!preg_match('/^'.$kind.'(?:\(\d+\))? unsigned$/',$columns[$column]??''))throw new RuntimeException("Unexpected type: $table.$column");
        }
        foreach($table==='courses'?['code','name','description','created_at','updated_at','is_active']:['assigned_at','updated_at','is_active'] as $column){
            if(!isset($columns[$column]))throw new RuntimeException("Missing column: $table.$column");
        }
        $engine=DB::val('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table]);
        if($actual!==$expected||$engine!=='InnoDB'){throw new RuntimeException("Unexpected existing schema for $table; inspect before proceeding.");}
    }
    $refs=[
        'courses'=>['users:created_by'],
        'course_subjects'=>['courses:course_id','subjects:subject_id','users:assigned_by'],
        'centre_courses'=>['centres:centre_id','courses:course_id','users:assigned_by'],
        'batch_courses'=>['batches:batch_id','courses:course_id','users:assigned_by'],
        'batch_subjects'=>['batch_courses:batch_id,course_id','course_subjects:course_id,subject_id','users:assigned_by'],
        'teacher_batch_subjects'=>['batch_subjects:batch_id,course_id,subject_id','users:assigned_by','users:teacher_id'],
    ];
    // The separately approved cover migration extends courses without changing schema v1 keys.
    if(DB::val("SELECT setting_value FROM app_settings WHERE setting_key='content_covers_schema_version'")==='1')$refs['courses'][]='media_assets:cover_asset_id';
    foreach($refs as $table=>$expected){
        $rows=DB::all("SELECT REFERENCED_TABLE_NAME AS parent,GROUP_CONCAT(COLUMN_NAME ORDER BY ORDINAL_POSITION) AS cols FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND REFERENCED_TABLE_NAME IS NOT NULL GROUP BY CONSTRAINT_NAME,REFERENCED_TABLE_NAME",[$table]);
        $actual=array_map(fn($r)=>$r['parent'].':'.$r['cols'],$rows);sort($actual);sort($expected);
        if($actual!==$expected||DB::val("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? AND DELETE_RULE='CASCADE'",[$table]))throw new RuntimeException("Unexpected foreign keys: $table");
    }
    $uniqueCode=DB::all("SHOW INDEX FROM courses WHERE Non_unique=0 AND Column_name='code'");
    if(!$uniqueCode)throw new RuntimeException('Missing unique course code index.');
    DB::run("INSERT INTO app_settings(setting_key,setting_value) VALUES ('global_courses_schema_version','1') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
    echo "Global course schema v1 ready. No legacy data or access rules changed; no assignments backfilled.\n";
}finally{DB::val("SELECT RELEASE_LOCK('lms_global_courses_schema')");}
