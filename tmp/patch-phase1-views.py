from pathlib import Path
p=Path('app/Views/media.php'); t=p.read_text(encoding='utf-8')
needle='<form method="post" enctype="multipart/form-data"><?= csrf_field() ?>'
replacement=needle+'''<?php if(Authorization::isCentreAdmin()): ?><label><?= e(tr('Centre')) ?><select name="centre_id" required><?php foreach(M::options('centres') as $centre): ?><option value="<?= (int)$centre['id'] ?>"><?= e($centre['label']) ?></option><?php endforeach; ?></select></label><?php endif; ?>'''
assert t.count(needle)==1;t=t.replace(needle,replacement);p.write_text(t,encoding='utf-8')
p=Path('app/Views/staff_dashboard.php');t=p.read_text(encoding='utf-8')
t=t.replace("$score=$admin?DB::val", "$score=Authorization::isSuperAdmin()?DB::val")
t=t.replace("[$uid]);\nif(Authorization::isCentreAdmin())", "[$uid]);\nif(Authorization::isCentreAdmin())",1)
# Do not execute Teacher-only/global queries for centre Admins before computing their scoped result.
t=t.replace(":DB::val(\"SELECT ROUND(AVG(percentage))", ":(Authorization::isTeacher()?DB::val(\"SELECT ROUND(AVG(percentage))",1)
t=t.replace("teacher_id=?)\",[$uid]);", "teacher_id=?)\",[$uid]):null);",1)
t=t.replace("$monthly=$admin?DB::all", "$monthly=Authorization::isSuperAdmin()?DB::all",1)
needle=":DB::all(\"SELECT DATE_FORMAT(enrolled_at"
assert needle in t;t=t.replace(needle,":(Authorization::isTeacher()?DB::all(\"SELECT DATE_FORMAT(enrolled_at",1)
needle="GROUP BY month\",[$uid]);"
assert needle in t;t=t.replace(needle,"GROUP BY month\",[$uid]):[]);",1)
p.write_text(t,encoding='utf-8')
