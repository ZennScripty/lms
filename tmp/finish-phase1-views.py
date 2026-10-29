from pathlib import Path
p=Path('app/Support/ui.php');t=p.read_text(encoding='utf-8')
t=t.replace("\\App\\Core\\Authorization::isSuperAdmin() ? 'ADMIN WORKSPACE'", "\\App\\Core\\Authorization::isAdmin() ? 'ADMIN WORKSPACE'")
p.write_text(t,encoding='utf-8')
p=Path('app/Views/staff_reports.php');t=p.read_text(encoding='utf-8')
needle='<form method="get" class="filter-bar"><?php if($studentId): ?>'
replacement='<form method="get" class="filter-bar"><?php if($centreFilter): ?><input type="hidden" name="centre_id" value="<?= $centreFilter ?>"><?php endif; ?><?php if($studentId): ?>'
assert t.count(needle)==1;t=t.replace(needle,replacement);p.write_text(t,encoding='utf-8')
