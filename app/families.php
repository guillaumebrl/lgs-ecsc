<?php
declare(strict_types=1);

function family_situation_label(string $value): string {
    return match ($value) {
        'married' => 'Mariés',
        'civil_union' => 'Pacsés / union civile',
        'cohabiting' => 'Vie commune',
        'separated' => 'Séparés',
        'divorced' => 'Divorcés',
        'single_parent' => 'Famille monoparentale',
        'widowed' => 'Veuf / veuve',
        default => 'Autre situation',
    };
}

function family_addressee(int $familyId): string {
    $family = one('SELECT * FROM families WHERE id=?', [$familyId]);
    if (!$family) return '';
    if ($family['addressee_mode'] === 'custom' && trim((string)$family['custom_addressee']) !== '') {
        return trim($family['custom_addressee']);
    }
    $guardians = rows('SELECT * FROM guardians WHERE family_id=? AND receives_bulletin=1 ORDER BY display_order,id', [$familyId]);
    if (!$guardians) return '';
    if ($family['addressee_mode'] === 'shared_couple' && count($guardians) >= 2) {
        $first = $guardians[0];
        $second = $guardians[1];
        $surname = trim($first['last_name']);
        return trim($first['title'] . ' et ' . $second['title'] . ' ' . $first['first_name'] . ' ' . $surname);
    }
    return implode(' et ', array_map(
        fn(array $g): string => trim($g['title'] . ' ' . $g['first_name'] . ' ' . $g['last_name']),
        $guardians
    ));
}

function handle_family_actions(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    $action = $_POST['action'] ?? '';
    if (!in_array($action, ['add_family', 'add_family_student'], true)) return;
    require_role(['admin']);

    if ($action === 'add_family') {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('INSERT INTO families(family_label,family_situation,addressee_mode,custom_addressee,address_line1,address_line2,postal_code,city) VALUES(?,?,?,?,?,?,?,?)');
            $stmt->execute([
                trim($_POST['family_label']), $_POST['family_situation'], $_POST['addressee_mode'],
                trim($_POST['custom_addressee']) ?: null, trim($_POST['address_line1']) ?: null,
                trim($_POST['address_line2']) ?: null, trim($_POST['postal_code']) ?: null,
                strtoupper(trim($_POST['city'])) ?: null,
            ]);
            $familyId = (int)$pdo->lastInsertId();
            $guardianStmt = $pdo->prepare('INSERT INTO guardians(family_id,title,first_name,last_name,relationship,email,phone,legal_guardian,receives_bulletin,display_order) VALUES(?,?,?,?,?,?,?,?,?,?)');
            foreach ($_POST['guardians'] ?? [] as $index => $guardian) {
                if (trim($guardian['first_name'] ?? '') === '' || trim($guardian['last_name'] ?? '') === '') continue;
                $guardianStmt->execute([
                    $familyId, $guardian['title'], trim($guardian['first_name']), strtoupper(trim($guardian['last_name'])),
                    trim($guardian['relationship'] ?: 'Parent'), strtolower(trim($guardian['email'])) ?: null,
                    trim($guardian['phone']) ?: null, isset($guardian['legal_guardian']) ? 1 : 0,
                    isset($guardian['receives_bulletin']) ? 1 : 0, $index + 1,
                ]);
            }
            $pdo->commit();
            audit('create', 'family', $familyId);
            flash('Famille et responsables légaux enregistrés.');
            redirect('index.php?page=families&family_id=' . $familyId);
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    if ($action === 'add_family_student') {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('INSERT INTO students(registration_number,last_name,first_name,birth_date,family_id) VALUES(?,?,?,?,?)');
            $stmt->execute([
                trim($_POST['registration_number']), strtoupper(trim($_POST['last_name'])), trim($_POST['first_name']),
                $_POST['birth_date'] ?: null, (int)$_POST['family_id'],
            ]);
            $studentId = (int)$pdo->lastInsertId();
            $pdo->prepare('INSERT INTO enrollments(student_id,class_id) VALUES(?,?)')->execute([$studentId, (int)$_POST['class_id']]);
            $pdo->commit();
            audit('create', 'student', $studentId, null, ['family_id' => (int)$_POST['family_id']]);
            flash('Élève ajouté à la famille.');
            redirect('index.php?page=families&family_id=' . (int)$_POST['family_id']);
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}

function render_families_page(): never {
    require_role(['admin']);
    $families = rows('SELECT f.*,COUNT(DISTINCT s.id) children_count,COUNT(DISTINCT g.id) guardians_count FROM families f LEFT JOIN students s ON s.family_id=f.id LEFT JOIN guardians g ON g.family_id=f.id GROUP BY f.id ORDER BY f.family_label');
    $familyId = (int)($_GET['family_id'] ?? 0);
    $family = $familyId ? one('SELECT * FROM families WHERE id=?', [$familyId]) : null;
    $guardians = $family ? rows('SELECT * FROM guardians WHERE family_id=? ORDER BY display_order,id', [$familyId]) : [];
    $children = $family ? rows('SELECT s.*,c.name class_name FROM students s LEFT JOIN enrollments e ON e.student_id=s.id LEFT JOIN classes c ON c.id=e.class_id WHERE s.family_id=? ORDER BY s.last_name,s.first_name', [$familyId]) : [];
    $classes = rows('SELECT c.id,c.name,y.name year_name FROM classes c JOIN school_years y ON y.id=c.school_year_id ORDER BY y.starts_on DESC,c.name');
    header_html('Familles et responsables légaux');
    ?><link rel="stylesheet" href="assets/families.css">
    <div class="family-layout">
      <section class="panel family-list">
        <div class="panel-head"><div><p class="eyebrow">Répertoire familial</p><h2><?=count($families)?> familles</h2></div></div>
        <?php foreach ($families as $item): ?>
          <a class="family-card <?=$familyId===(int)$item['id']?'selected':''?>" href="?page=families&family_id=<?=$item['id']?>">
            <span><strong><?=e($item['family_label'])?></strong><small><?=e(family_situation_label($item['family_situation']))?></small></span>
            <span><?=e($item['children_count'])?> élève(s)</span>
          </a>
        <?php endforeach; ?>
        <?php if (!$families): ?><p class="empty">Aucune famille enregistrée.</p><?php endif; ?>
      </section>

      <?php if ($family): ?>
        <section>
          <article class="panel">
            <p class="eyebrow"><?=e(family_situation_label($family['family_situation']))?></p>
            <h2><?=e($family['family_label'])?></h2>
            <div class="addressee-preview"><small>Formule portée sur le bulletin</small><strong><?=e(family_addressee($familyId) ?: 'Aucun destinataire défini')?></strong></div>
            <?php foreach ($guardians as $guardian): ?>
              <div class="guardian-row"><span><strong><?=e($guardian['title'].' '.$guardian['first_name'].' '.$guardian['last_name'])?></strong><small><?=e($guardian['relationship'])?> · <?=e($guardian['email'] ?: 'sans e-mail')?> · <?=e($guardian['phone'] ?: 'sans téléphone')?></small></span><span class="tag"><?=$guardian['receives_bulletin']?'Bulletin':'Contact seul'?></span></div>
            <?php endforeach; ?>
            <?php if ($family['address_line1']): ?><p class="postal-address"><?=e($family['address_line1'])?><br><?=e($family['address_line2'])?><br><?=e(trim($family['postal_code'].' '.$family['city']))?></p><?php endif; ?>
          </article>
          <article class="panel">
            <h2>Enfants de la famille</h2>
            <?php foreach ($children as $child): ?><p class="list-row"><strong><?=e($child['first_name'].' '.$child['last_name'])?></strong><span><?=e($child['class_name'] ?: 'Sans classe')?></span></p><?php endforeach; ?>
            <?php if (!$children): ?><p class="muted">Aucun élève lié à cette famille.</p><?php endif; ?>
          </article>
          <form class="panel" method="post">
            <h2>Ajouter un élève à cette famille</h2>
            <input type="hidden" name="_token" value="<?=csrf()?>"><input type="hidden" name="action" value="add_family_student"><input type="hidden" name="family_id" value="<?=$familyId?>">
            <label>Matricule<input required name="registration_number"></label>
            <div class="form-row"><label>Nom<input required name="last_name"></label><label>Prénom<input required name="first_name"></label></div>
            <div class="form-row"><label>Date de naissance<input type="date" name="birth_date"></label><label>Classe<select required name="class_id"><?php foreach($classes as $class):?><option value="<?=$class['id']?>"><?=e($class['name'].' · '.$class['year_name'])?></option><?php endforeach?></select></label></div>
            <button>Ajouter l’élève</button>
          </form>
        </section>
      <?php else: ?>
        <form class="panel family-form" method="post">
          <p class="eyebrow">Nouvelle fiche</p><h2>Créer une famille</h2>
          <input type="hidden" name="_token" value="<?=csrf()?>"><input type="hidden" name="action" value="add_family">
          <label>Nom interne de la famille<input required name="family_label" placeholder="Famille Martin-Durand"><small>Utilisé uniquement pour retrouver le dossier.</small></label>
          <label>Situation familiale<select name="family_situation" data-family-situation><option value="married">Mariés</option><option value="civil_union">Pacsés / union civile</option><option value="cohabiting">Vie commune</option><option value="separated">Séparés</option><option value="divorced">Divorcés</option><option value="single_parent">Famille monoparentale</option><option value="widowed">Veuf / veuve</option><option value="other">Autre</option></select></label>
          <label>Formule d’adressage du bulletin<select name="addressee_mode" data-addressee-mode><option value="shared_couple" selected>M. et Mme Prénom Nom commun</option><option value="individual_names">Deux noms complets distincts</option><option value="custom">Formule personnalisée</option></select></label>
          <label data-custom-addressee hidden>Formule personnalisée<input name="custom_addressee" placeholder="Destinataire exact à imprimer"></label>
          <?php for ($i=0; $i<2; $i++): ?>
            <fieldset><legend>Responsable légal <?=$i+1?></legend>
              <div class="guardian-fields"><label>Civilité<select name="guardians[<?=$i?>][title]"><option>M.</option><option>Mme</option><option>Mx</option><option>Autre</option></select></label><label>Prénom<input <?=$i===0?'required':''?> name="guardians[<?=$i?>][first_name]"></label><label>Nom<input <?=$i===0?'required':''?> name="guardians[<?=$i?>][last_name]"></label></div>
              <div class="guardian-fields"><label>Lien avec l’élève<input name="guardians[<?=$i?>][relationship]" value="Parent"></label><label>E-mail<input type="email" name="guardians[<?=$i?>][email]"></label><label>Téléphone<input type="tel" name="guardians[<?=$i?>][phone]"></label></div>
              <div class="inline-checks"><label class="check"><input type="checkbox" name="guardians[<?=$i?>][legal_guardian]" value="1" checked> Responsable légal</label><label class="check"><input type="checkbox" name="guardians[<?=$i?>][receives_bulletin]" value="1" checked> Destinataire du bulletin</label></div>
            </fieldset>
          <?php endfor; ?>
          <fieldset><legend>Adresse postale</legend><label>Adresse<input name="address_line1"></label><label>Complément<input name="address_line2"></label><div class="form-row"><label>Code postal<input name="postal_code"></label><label>Ville<input name="city"></label></div></fieldset>
          <button>Enregistrer la famille</button>
        </form>
      <?php endif; ?>
    </div>
    <?php
    footer_html();
    exit;
}
