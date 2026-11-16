<?php
// Public, read-only list of published residents and their glass lineage. See Admin → Integrations.
// Lineage rows use glassdatabase.org's lineage-form fields (student_name, mentor_name, relation,
// where, years, note), so the Glass Database can import them as they are.
require __DIR__ . '/../../app/bootstrap.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$out = [];
foreach (residents() as $r) {
    $self = ['name' => $r['name'], 'gid' => $r['gdb_gid'] ?: null];
    $lineage = [];
    foreach (resident_lineage((int)$r['id']) as $l) {
        [, $student_side, $relation] = CVG_RELATIONS[$l['relation']] ?? [null, 'resident', 'Collaborated with'];
        $other = ['name' => $l['person'], 'gid' => $l['gdb_gid'] ?: null];
        [$student, $mentor] = $student_side === 'resident' ? [$self, $other] : [$other, $self];
        $lineage[] = [
            'student_name' => $student['name'], 'student_gid' => $student['gid'],
            'mentor_name' => $mentor['name'], 'mentor_gid' => $mentor['gid'],
            'relation' => $relation, 'where' => $l['place'], 'years' => $l['years'], 'note' => $l['note'],
            'source' => site_url(resident_url($r)),
        ];
    }
    $out[] = [
        'name' => $r['name'],
        'slug' => $r['slug'],
        'url' => site_url(resident_url($r)),
        'year' => $r['year'],
        'project_title' => $r['project_title'],
        'summary' => $r['summary'],
        'pronouns' => $r['pronouns'],
        'website' => $r['website'] ?: null,
        'instagram' => $r['instagram'] !== '' ? 'https://www.instagram.com/' . $r['instagram'] . '/' : null,
        'gdb_gid' => $r['gdb_gid'] ?: null,
        'gdb_url' => $r['gdb_gid'] ? gdb_maker_url($r['gdb_gid']) : null,
        'headshot_url' => $r['headshot'] ? site_url(upload_url($r['headshot'])) : null,
        'cv_url' => $r['cv_file'] ? site_url(upload_url($r['cv_file'])) : null,
        'updated_at' => $r['updated_at'],
        'lineage' => $lineage,
    ];
}
echo json_encode(['studio' => 'Charlottesville Glass', 'residents' => $out], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
