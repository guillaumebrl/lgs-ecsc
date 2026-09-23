<?php
declare(strict_types=1);

function handle_student_management_actions(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }
    $action = $_POST['action'] ?? '';
    if (!in_array($action, ['archive_student', 'restore_student', 'hard_delete_student', 'update_student', 'remove_student_enrollment', 'update_student_family', 'save_student_guardian'], true)) {
        return;
    }
    require_management_mode();
    $studentId = (int)$_POST['student_id'];
    $student = one('SELECT id,last_name,first_name,active FROM students WHERE id=?', [$studentId]);
    if (!$student) {
        flash('Élève introuvable.', 'error');
        redirect('index.php?page=student-management');
    }
    if ($action === 'hard_delete_student') {
        if (($_POST['confirmation'] ?? '') !== 'SUPPRIMER') {
            flash('Saisissez SUPPRIMER pour confirmer.', 'error');
            redirect('index.php?page=student-management');
        }
        db()->prepare('DELETE FROM students WHERE id=?')->execute([$studentId]);
        audit('delete', 'student', $studentId, $student);
        flash('Élève et données associées supprimés définitivement.');
        redirect('index.php?page=student-management');
    }
    if ($action === 'remove_student_enrollment') {
        $classId = (int)($_POST['class_id'] ?? 0);
        db()->prepare('DELETE FROM enrollments WHERE student_id=? AND class_id=?')->execute([$studentId,$classId]);
        audit('delete', 'enrollment', $studentId, ['student_id' => $studentId,'class_id' => $classId]);
        flash('L’élève a été retiré du niveau et reste disponible dans le répertoire général.');
        redirect('index.php?page=student-detail&id='.$studentId);
    }
    if ($action === 'update_student') {
        $before = $student;
        $familyId = $_POST['family_id'] !== '' ? (int)$_POST['family_id'] : null;
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $ine = trim($_POST['registration_number'] ?? '');
            $pdo->prepare('UPDATE students SET registration_number=?,last_name=?,first_name=?,birth_date=?,family_id=? WHERE id=?')->execute([$ine !== '' ? $ine : null,strtoupper(trim($_POST['last_name'])),trim($_POST['first_name']),$_POST['birth_date'] ?: null,$familyId,$studentId]);
            $pdo->prepare('DELETE e FROM enrollments e JOIN classes c ON c.id=e.class_id JOIN school_years y ON y.id=c.school_year_id WHERE e.student_id=? AND y.active=1')->execute([$studentId]);
            $pdo->prepare('INSERT INTO enrollments(student_id,class_id) VALUES(?,?)')->execute([$studentId,(int)$_POST['class_id']]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        audit('update', 'student', $studentId, $before, ['family_id' => $familyId,'class_id' => (int)$_POST['class_id']]);
        flash('Données de l’élève mises à jour.');
        redirect('index.php?page=student-detail&id='.$studentId);
    }
    if ($action === 'update_student_family') {
        $familyId = (int)$_POST['family_id'];
        $before = one('SELECT * FROM families WHERE id=?', [$familyId]);
        db()->prepare('UPDATE families SET family_label=?,family_situation=?,addressee_mode=?,custom_addressee=?,address_line1=?,address_line2=?,postal_code=?,city=? WHERE id=?')->execute([trim($_POST['family_label']),$_POST['family_situation'],$_POST['addressee_mode'],trim($_POST['custom_addressee']) ?: null,trim($_POST['address_line1']) ?: null,trim($_POST['address_line2']) ?: null,trim($_POST['postal_code']) ?: null,strtoupper(trim($_POST['city'])) ?: null,$familyId]);
        audit('update', 'family', $familyId, $before, $_POST);
        flash('Dossier familial mis à jour.');
        redirect('index.php?page=student-detail&id='.$studentId);
    }
    if ($action === 'save_student_guardian') {
        $guardianId = (int)($_POST['guardian_id'] ?? 0);
        $values = [$_POST['title'],trim($_POST['first_name']),strtoupper(trim($_POST['last_name'])),trim($_POST['relationship']),strtolower(trim($_POST['email'])) ?: null,trim($_POST['phone']) ?: null,isset($_POST['legal_guardian']) ? 1 : 0,isset($_POST['receives_bulletin']) ? 1 : 0];
        if ($guardianId) {
            $before = one('SELECT * FROM guardians WHERE id=? AND family_id=?', [$guardianId,(int)$_POST['family_id']]);
            db()->prepare('UPDATE guardians SET title=?,first_name=?,last_name=?,relationship=?,email=?,phone=?,legal_guardian=?,receives_bulletin=? WHERE id=? AND family_id=?')->execute([...$values,$guardianId,(int)$_POST['family_id']]);
            audit('update', 'guardian', $guardianId, $before, $values);
        } else {
            $stmt = db()->prepare('INSERT INTO guardians(family_id,title,first_name,last_name,relationship,email,phone,legal_guardian,receives_bulletin,display_order) VALUES(?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute([(int)$_POST['family_id'],...$values,(int)$_POST['display_order']]);
            audit('create', 'guardian', (int)db()->lastInsertId());
        }
        flash('Responsable légal enregistré.');
        redirect('index.php?page=student-detail&id='.$studentId);
    }
    if ($action === 'archive_student') {
        db()->prepare('UPDATE students SET active=0 WHERE id=?')->execute([$studentId]);
        audit('archive', 'student', $studentId, $student, ['active' => 0]);
        flash('Élève archivé. Ses notes et bulletins sont conservés et il peut être restauré.');
    } else {
        db()->prepare('UPDATE students SET active=1 WHERE id=?')->execute([$studentId]);
        audit('restore', 'student', $studentId, $student, ['active' => 1]);
        flash('Élève restauré.');
    }
    redirect('index.php?page=student-management');
}

function render_student_detail_page(): never
{
    require_management_mode();
    $studentId = (int)($_GET['id'] ?? 0);
    $student = one('SELECT s.*,e.class_id,c.level class_name FROM students s LEFT JOIN enrollments e ON e.student_id=s.id LEFT JOIN classes c ON c.id=e.class_id LEFT JOIN school_years y ON y.id=c.school_year_id AND y.active=1 WHERE s.id=? ORDER BY y.active DESC LIMIT 1', [$studentId]);
    if (!$student) {
        http_response_code(404);
        exit('Élève introuvable');
    }
    $classes = rows('SELECT c.id,c.level,y.name year_name FROM classes c JOIN school_years y ON y.id=c.school_year_id ORDER BY y.starts_on DESC,c.sort_order,c.level');
    $families = rows('SELECT id,family_label FROM families ORDER BY family_label');
    $family = $student['family_id'] ? one('SELECT * FROM families WHERE id=?', [(int)$student['family_id']]) : null;
    $guardians = $family ? rows('SELECT * FROM guardians WHERE family_id=? ORDER BY display_order,id', [$family['id']]) : [];
    header_html('Fiche de '.$student['first_name'].' '.$student['last_name']);
    ?><link rel="stylesheet" href="assets/families.css"><link rel="stylesheet" href="assets/admin.css"><link rel="stylesheet" href="assets/student-management.css"><link rel="stylesheet" href="assets/student-detail.css">
    <nav class="student-subnav"><a class="button secondary" href="?page=students">← Liste des élèves</a><?php if ($family):?><a class="button secondary" href="?page=families&family_id=<?=$family['id']?>">Voir toute la famille</a><?php else:?><a class="button secondary" href="?page=families">Créer une famille</a><?php endif?><?php if ($student['class_id']):?><form method="post" onsubmit="return confirm('Retirer cet élève de son niveau actuel ?')"><input type="hidden" name="_token" value="<?=csrf()?>"><input type="hidden" name="action" value="remove_student_enrollment"><input type="hidden" name="student_id" value="<?=$studentId?>"><input type="hidden" name="class_id" value="<?=$student['class_id']?>"><button class="button secondary"><i class="bi bi-person-dash"></i> Retirer du niveau</button></form><?php endif?></nav>
    <div class="two-col"><form class="panel" method="post"><p class="eyebrow">Identité et inscription</p><h2>Données de l’élève</h2><input type="hidden" name="_token" value="<?=csrf()?>"><input type="hidden" name="action" value="update_student"><input type="hidden" name="student_id" value="<?=$studentId?>"><label>N° INE <small>(facultatif)</small><input name="registration_number" value="<?=e($student['registration_number'])?>"></label><div class="form-row"><label>Nom<input required name="last_name" value="<?=e($student['last_name'])?>"></label><label>Prénom<input required name="first_name" value="<?=e($student['first_name'])?>"></label></div><label>Date de naissance<input type="date" name="birth_date" value="<?=e($student['birth_date'])?>"></label><label>Niveau<select required name="class_id"><?php foreach ($classes as $class):?><option value="<?=$class['id']?>" <?=$student['class_id'] === $class['id'] ? 'selected' : ''?>><?=e($class['level'].' · '.$class['year_name'])?></option><?php endforeach?></select></label><label>Famille<select name="family_id"><option value="">Aucune famille</option><?php foreach ($families as $item):?><option value="<?=$item['id']?>" <?=$student['family_id'] === $item['id'] ? 'selected' : ''?>><?=e($item['family_label'])?></option><?php endforeach?></select></label><button>Enregistrer l’élève</button></form>
    <section><?php if ($family):?><form class="panel" method="post"><p class="eyebrow">Dossier familial</p><h2><?=e($family['family_label'])?></h2><input type="hidden" name="_token" value="<?=csrf()?>"><input type="hidden" name="action" value="update_student_family"><input type="hidden" name="student_id" value="<?=$studentId?>"><input type="hidden" name="family_id" value="<?=$family['id']?>"><label>Nom interne<input required name="family_label" value="<?=e($family['family_label'])?>"></label><label>Situation<select name="family_situation"><?php foreach (['married' => 'Mariés','civil_union' => 'Pacsés / union civile','cohabiting' => 'Vie commune','separated' => 'Séparés','divorced' => 'Divorcés','single_parent' => 'Famille monoparentale','widowed' => 'Veuf / veuve','other' => 'Autre'] as $key => $label):?><option value="<?=$key?>" <?=$family['family_situation'] === $key ? 'selected' : ''?>><?=e($label)?></option><?php endforeach?></select></label><label>Formule du bulletin<select name="addressee_mode"><option value="shared_couple" <?=$family['addressee_mode'] === 'shared_couple' ? 'selected' : ''?>>M. et Mme Prénom Nom commun</option><option value="individual_names" <?=$family['addressee_mode'] === 'individual_names' ? 'selected' : ''?>>Noms complets distincts</option><option value="custom" <?=$family['addressee_mode'] === 'custom' ? 'selected' : ''?>>Personnalisée</option></select></label><label>Formule personnalisée<input name="custom_addressee" value="<?=e($family['custom_addressee'])?>"></label><label>Adresse<input name="address_line1" value="<?=e($family['address_line1'])?>"></label><label>Complément<input name="address_line2" value="<?=e($family['address_line2'])?>"></label><div class="form-row"><label>Code postal<input name="postal_code" value="<?=e($family['postal_code'])?>"></label><label>Ville<input name="city" value="<?=e($family['city'])?>"></label></div><button>Enregistrer la famille</button></form><?php else:?><div class="panel empty-state"><h2>Aucun responsable rattaché</h2><p>Choisissez une famille dans la fiche de l’élève ou créez d’abord un nouveau dossier familial.</p><a class="button" href="?page=families">Créer une famille</a></div><?php endif?></section></div>
    <?php if ($family):?><section class="panel"><p class="eyebrow">Contacts</p><h2>Responsables légaux</h2><div class="guardian-edit-grid"><?php for ($i = 0;$i < max(2, count($guardians));$i++):$guardian = $guardians[$i] ?? null;?><form class="edit-card" method="post"><h3>Responsable <?=$i + 1?></h3><input type="hidden" name="_token" value="<?=csrf()?>"><input type="hidden" name="action" value="save_student_guardian"><input type="hidden" name="student_id" value="<?=$studentId?>"><input type="hidden" name="family_id" value="<?=$family['id']?>"><input type="hidden" name="guardian_id" value="<?=e($guardian['id'] ?? '')?>"><input type="hidden" name="display_order" value="<?=$i + 1?>"><div class="guardian-fields"><label>Civilité<select name="title"><?php foreach (['M.','Mme'] as $title):?><option <?=$title === ($guardian['title'] ?? '') ? 'selected' : ''?>><?=e($title)?></option><?php endforeach?></select></label><label>Prénom<input required name="first_name" value="<?=e($guardian['first_name'] ?? '')?>"></label><label>Nom<input required name="last_name" value="<?=e($guardian['last_name'] ?? '')?>"></label></div><div class="guardian-fields"><label>Lien<input required name="relationship" value="<?=e($guardian['relationship'] ?? 'Parent')?>"></label><label>E-mail<input type="email" name="email" value="<?=e($guardian['email'] ?? '')?>"></label><label>Téléphone<input name="phone" value="<?=e($guardian['phone'] ?? '')?>"></label></div><div class="inline-checks"><label class="check"><input type="checkbox" name="legal_guardian" value="1" <?=!$guardian || $guardian['legal_guardian'] ? 'checked' : ''?>> Responsable légal</label><label class="check"><input type="checkbox" name="receives_bulletin" value="1" <?=!$guardian || $guardian['receives_bulletin'] ? 'checked' : ''?>> Reçoit le bulletin</label></div><button>Enregistrer</button></form><?php endfor?></div></section><?php endif?>
    <?php footer_html();
    exit;
}

function render_student_management_page(): never
{
    require_management_mode();
    $students = rows('SELECT s.*,c.level class_name,y.name year_name,f.family_label FROM students s LEFT JOIN enrollments e ON e.student_id=s.id LEFT JOIN classes c ON c.id=e.class_id LEFT JOIN school_years y ON y.id=c.school_year_id LEFT JOIN families f ON f.id=s.family_id WHERE y.active=1 OR y.id IS NULL ORDER BY s.active DESC,s.last_name,s.first_name');
    header_html('Gérer les élèves');
    ?><link rel="stylesheet" href="assets/admin.css"><link rel="stylesheet" href="assets/student-management.css?v=25">
    <nav class="student-section-nav"><a class="button secondary" href="?page=students">Élèves</a><a class="button secondary" href="?page=families">Familles et responsables légaux</a><a class="button" href="?page=student-management">Supprimer ou restaurer un élève</a></nav>
    <section class="panel">
      <p class="eyebrow">Administration</p><h2>Suppression et restauration</h2>
      <div class="alert info">Archiver conserve toutes les données et permet une restauration. La poubelle supprime définitivement l’élève, ses notes, ses bulletins et ses autres données associées.</div>
      <div class="table-wrap"><table><thead><tr><th>Élève</th><th>Classe</th><th>Famille</th><th>État</th><th>Actions</th></tr></thead><tbody>
      <?php foreach ($students as $student):?><tr><td><strong><?=e($student['last_name'].' '.$student['first_name'])?></strong><small><?=e($student['registration_number'])?></small></td><td><?=e($student['class_name'] ?: '—')?><small><?=e($student['year_name'] ?: '')?></small></td><td><?=e($student['family_label'] ?: 'Non rattaché')?></td><td><span class="tag"><?=$student['active'] ? 'Actif' : 'Archivé'?></span></td><td><span class="table-actions"><form method="post" onsubmit="return confirm('Archiver cet élève ? Il disparaîtra des listes actives mais pourra être restauré.');"><input type="hidden" name="_token" value="<?=csrf()?>"><input type="hidden" name="action" value="archive_student"><input type="hidden" name="student_id" value="<?=$student['id']?>"><button class="icon-button" title="Archiver" aria-label="Archiver" <?=$student['active'] ? '' : 'disabled'?>> <i class="bi bi-archive"></i></button></form><form method="post"><input type="hidden" name="_token" value="<?=csrf()?>"><input type="hidden" name="action" value="restore_student"><input type="hidden" name="student_id" value="<?=$student['id']?>"><button class="icon-button" title="Restaurer" aria-label="Restaurer" <?=$student['active'] ? 'disabled' : ''?>><i class="bi bi-bootstrap-reboot"></i></button></form><form method="post" onsubmit="return confirm('Supprimer définitivement cet élève et toutes ses données ? Cette opération est irréversible.');"><input type="hidden" name="_token" value="<?=csrf()?>"><input type="hidden" name="action" value="hard_delete_student"><input type="hidden" name="student_id" value="<?=$student['id']?>"><input type="hidden" name="confirmation" value="SUPPRIMER"><button class="icon-button danger" title="Supprimer définitivement" aria-label="Supprimer définitivement"><i class="bi bi-trash"></i></button></form></span></td></tr><?php endforeach?>
      </tbody></table></div>
    </section>
    <?php footer_html();
    exit;
}

function render_students_page_legacy(): never
{
    require_management_mode();
    $currentYearId = (int)(db()->query('SELECT COALESCE((SELECT id FROM school_years WHERE active=1 ORDER BY starts_on DESC LIMIT 1),(SELECT id FROM school_years ORDER BY starts_on DESC LIMIT 1),0)')->fetchColumn() ?: 0);
    $classes = $currentYearId ? rows('SELECT c.id,c.level name,y.name year_name FROM classes c JOIN school_years y ON y.id=c.school_year_id WHERE c.school_year_id=? ORDER BY c.sort_order,c.level', [$currentYearId]) : [];
    $students = $currentYearId
        ? rows('SELECT DISTINCT s.*,c.level class_name,y.name year_name,f.family_label FROM students s LEFT JOIN enrollments e ON e.student_id=s.id LEFT JOIN classes c ON c.id=e.class_id AND c.school_year_id=? LEFT JOIN school_years y ON y.id=c.school_year_id LEFT JOIN families f ON f.id=s.family_id WHERE s.active=1 ORDER BY c.sort_order,s.last_name,s.first_name', [$currentYearId])
        : rows('SELECT s.*,NULL class_name,NULL year_name,f.family_label FROM students s LEFT JOIN families f ON f.id=s.family_id WHERE s.active=1 ORDER BY s.last_name,s.first_name');
    header_html('Élèves');
    ?><link rel="stylesheet" href="assets/student-management.css?v=25"><link rel="stylesheet" href="assets/families.css?v=25">
    <nav class="student-section-nav"><a class="button" href="?page=students">Élèves</a><a class="button secondary" href="?page=families">Familles et responsables légaux</a><a class="button secondary" href="?page=student-management">Supprimer ou restaurer un élève</a></nav>
    <div class="two-col">
      <section class="panel"><div class="panel-head"><div><p class="eyebrow">Répertoire</p><h2><?=count($students)?> élève<?=count($students) > 1 ? 's' : ''?></h2></div><input class="search" type="search" placeholder="Rechercher un élève…" data-filter-table></div><div class="table-wrap"><table data-table><thead><tr><th>Élève</th><th>N° INE</th><th>Niveau</th><th>Famille</th></tr></thead><tbody><?php foreach ($students as $student):?><tr><td><strong><a href="?page=student-detail&amp;id=<?=$student['id']?>"><?=e($student['last_name'].' '.$student['first_name'])?></a></strong></td><td><?=e($student['registration_number'] ?: 'En attente')?></td><td><?=e($student['class_name'] ?: 'Non affecté')?><small><?=e($student['year_name'] ?: '')?></small></td><td><?=e($student['family_label'] ?: 'Non rattaché')?></td></tr><?php endforeach?><?php if (!$students):?><tr><td colspan="4" class="empty">Aucun élève à afficher.</td></tr><?php endif?></tbody></table></div></section>
      <section class="sticky"><form class="panel" method="post"><p class="eyebrow">Création individuelle</p><h2>Inscrire un élève</h2><input type="hidden" name="_token" value="<?=csrf()?>"><input type="hidden" name="action" value="add_student"><label>N° INE <small>(facultatif)</small><input name="registration_number"></label><div class="form-row"><label>Nom<input required name="last_name"></label><label>Prénom<input required name="first_name"></label></div><label>Date de naissance<input type="date" name="birth_date"></label><label>Niveau<select required name="class_id" <?=$classes ? '' : 'disabled'?>><?php foreach ($classes as $class):?><option value="<?=$class['id']?>"><?=e($class['name'].' · '.$class['year_name'])?></option><?php endforeach?></select></label><?php if (!$classes):?><div class="alert error">Créez d’abord un niveau dans Organisation → Classes.</div><?php endif?><button <?=$classes ? '' : 'disabled'?>>Inscrire l’élève</button></form><div class="panel family-entry"><p class="eyebrow">Création familiale</p><h2>Créer une famille avec ses enfants</h2><p>Ajoutez les responsables légaux, puis inscrivez un ou plusieurs enfants depuis le même dossier.</p><a class="button secondary" href="?page=families">Ouvrir les familles</a></div></section>
    </div>
    <?php footer_html();
    exit;
}

function render_students_page(): never
{
    require_management_mode();
    $yearId = (int)(db()->query('SELECT COALESCE((SELECT id FROM school_years WHERE active=1 ORDER BY starts_on DESC LIMIT 1),(SELECT id FROM school_years ORDER BY starts_on DESC LIMIT 1),0)')->fetchColumn() ?: 0);
    $classes = $yearId ? rows('SELECT c.id,c.level name FROM classes c WHERE c.school_year_id=? ORDER BY c.sort_order,c.level', [$yearId]) : [];
    $students = $yearId ? rows('SELECT DISTINCT s.*,c.level class_name,f.family_label FROM students s LEFT JOIN enrollments e ON e.student_id=s.id LEFT JOIN classes c ON c.id=e.class_id AND c.school_year_id=? LEFT JOIN families f ON f.id=s.family_id WHERE s.active=1 ORDER BY c.sort_order,s.last_name,s.first_name', [$yearId]) : rows('SELECT s.*,NULL class_name,f.family_label FROM students s LEFT JOIN families f ON f.id=s.family_id WHERE s.active=1 ORDER BY s.last_name,s.first_name');
    header_html('Élèves');?>
    <nav class="student-section-nav"><a class="button" href="?page=students">Élèves</a><a class="button secondary" href="?page=families">Familles et responsables légaux</a><a class="button secondary" href="?page=student-management">Supprimer ou restaurer un élève</a></nav>
    <div class="two-col"><section class="panel"><div class="panel-head"><div><p class="eyebrow">Répertoire</p><h2><?=count($students)?> élève<?=count($students) > 1 ? 's' : ''?></h2></div><input class="search" type="search" placeholder="Rechercher un élève…" data-filter-table></div><div class="table-wrap"><table data-table><thead><tr><th>Élève</th><th>N° INE</th><th>Niveau</th><th>Famille</th><th>Actions</th></tr></thead><tbody><?php foreach ($students as $student):?><tr><td><strong><?=e($student['last_name'].' '.$student['first_name'])?></strong></td><td><?=e($student['registration_number'] ?: 'En attente')?></td><td><?=e($student['class_name'] ?: 'Non affecté')?></td><td><?=e($student['family_label'] ?: 'Non rattaché')?></td><td><span class="table-actions"><a class="icon-button" href="?page=student-detail&amp;id=<?=$student['id']?>" title="Modifier" aria-label="Modifier"><i class="bi bi-pencil-square"></i></a><form method="post" onsubmit="return confirm('Archiver cet élève ? Il pourra être restauré ensuite.')"><input type="hidden" name="_token" value="<?=csrf()?>"><input type="hidden" name="action" value="archive_student"><input type="hidden" name="student_id" value="<?=$student['id']?>"><button class="icon-button danger" title="Archiver" aria-label="Archiver"><i class="bi bi-trash"></i></button></form></span></td></tr><?php endforeach?><?php if (!$students):?><tr><td colspan="5" class="empty">Aucun élève à afficher.</td></tr><?php endif?></tbody></table></div></section>
    <section class="sticky"><form class="panel" method="post"><p class="eyebrow">Création individuelle</p><h2>Inscrire un élève</h2><input type="hidden" name="_token" value="<?=csrf()?>"><input type="hidden" name="action" value="add_student"><label>N° INE <small>(facultatif)</small><input name="registration_number"></label><div class="form-row"><label>Nom<input required name="last_name"></label><label>Prénom<input required name="first_name"></label></div><label>Date de naissance <small>(facultative)</small><input type="date" name="birth_date"></label><label>Niveau<select required name="class_id" <?=$classes ? '' : 'disabled'?>><?php foreach ($classes as $class):?><option value="<?=$class['id']?>"><?=e($class['name'])?></option><?php endforeach?></select></label><button <?=$classes ? '' : 'disabled'?>>Inscrire l’élève</button></form><div class="panel family-entry"><p class="eyebrow">Création familiale</p><h2>Créer une famille avec ses enfants</h2><p>Ajoutez les responsables légaux, puis inscrivez un ou plusieurs enfants depuis le même dossier.</p><a class="button secondary" href="?page=families">Ouvrir les familles</a></div></section></div>
    <?php footer_html();
    exit;
}

function render_students_page_with_birthdate(): never
{
    require_management_mode();
    $yearId = (int)(db()->query('SELECT COALESCE((SELECT id FROM school_years WHERE active=1 ORDER BY starts_on DESC LIMIT 1),(SELECT id FROM school_years ORDER BY starts_on DESC LIMIT 1),0)')->fetchColumn() ?: 0);
    $classes = $yearId ? rows('SELECT id,level name FROM classes WHERE school_year_id=? ORDER BY sort_order,level', [$yearId]) : [];
    $students = $yearId ? rows('SELECT DISTINCT s.*,c.level class_name,c.sort_order class_sort_order FROM students s LEFT JOIN enrollments e ON e.student_id=s.id LEFT JOIN classes c ON c.id=e.class_id AND c.school_year_id=? WHERE s.active=1 ORDER BY c.sort_order,s.last_name,s.first_name', [$yearId]) : rows('SELECT s.*,NULL class_name,NULL class_sort_order FROM students s WHERE s.active=1 ORDER BY s.last_name,s.first_name');
    header_html('Élèves');?>
    <nav class="student-section-nav"><a class="button" href="?page=students">Élèves</a><a class="button secondary" href="?page=families">Familles et responsables légaux</a><a class="button secondary" href="?page=student-management">Supprimer ou restaurer un élève</a></nav>
    <div class="two-col"><section class="panel"><div class="panel-head"><div><p class="eyebrow">Répertoire</p><h2><?=count($students)?> élève<?=count($students) > 1 ? 's' : ''?></h2></div><input class="search" type="search" placeholder="Rechercher un élève…" data-filter-table></div><div class="table-wrap"><table data-table data-sortable-table><thead><tr><th><button type="button" class="table-sort" data-sort-column="0">Élève <span aria-hidden="true">↕</span></button></th><th>N° INE</th><th><button type="button" class="table-sort" data-sort-column="2" data-sort-type="number">Niveau <span aria-hidden="true">↕</span></button></th><th>Date de naissance</th><th>Actions</th></tr></thead><tbody><?php foreach ($students as $student):?><tr><td data-sort-value="<?=e($student['last_name'].' '.$student['first_name'])?>"><strong><?=e($student['last_name'].' '.$student['first_name'])?></strong></td><td><?=e($student['registration_number'] ?: 'En attente')?></td><td data-sort-value="<?=e($student['class_sort_order'] ?? 9999)?>"><?=e($student['class_name'] ?: 'Non affecté')?></td><td><?=!empty($student['birth_date']) ? date('d/m/Y', strtotime($student['birth_date'])) : 'Non renseignée'?></td><td><span class="table-actions"><a class="icon-button" href="?page=student-detail&amp;id=<?=$student['id']?>" title="Modifier" aria-label="Modifier"><i class="bi bi-pencil-square"></i></a><form method="post" onsubmit="return confirm('Archiver cet élève ? Il pourra être restauré ensuite.')"><input type="hidden" name="_token" value="<?=csrf()?>"><input type="hidden" name="action" value="archive_student"><input type="hidden" name="student_id" value="<?=$student['id']?>"><button class="icon-button danger" title="Archiver" aria-label="Archiver"><i class="bi bi-trash"></i></button></form></span></td></tr><?php endforeach?></tbody></table></div></section>
    <section class="sticky"><form class="panel" method="post"><p class="eyebrow">Création individuelle</p><h2>Inscrire un élève</h2><input type="hidden" name="_token" value="<?=csrf()?>"><input type="hidden" name="action" value="add_student"><label>N° INE <small>(facultatif)</small><input name="registration_number"></label><div class="form-row"><label>Nom<input required name="last_name"></label><label>Prénom<input required name="first_name"></label></div><label>Date de naissance<input type="date" name="birth_date"></label><label>Niveau<select required name="class_id" <?=$classes ? '' : 'disabled'?>><?php foreach ($classes as $class):?><option value="<?=$class['id']?>"><?=e($class['name'])?></option><?php endforeach?></select></label><button <?=$classes ? '' : 'disabled'?>>Inscrire l’élève</button></form><div class="panel family-entry"><p class="eyebrow">Création familiale</p><h2>Créer une famille avec ses enfants</h2><p>Ajoutez les responsables légaux, puis inscrivez un ou plusieurs enfants depuis le même dossier.</p><a class="button secondary" href="?page=families">Ouvrir les familles</a></div></section></div>
    <?php footer_html();
    exit;
}
