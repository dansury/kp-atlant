<?php
/**
 * API: unsent text of any field (module 063) — list, save, clear.
 */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once ROOT . '/lib/field_drafts.php';

$manager = requireAuth();
$mid = (int)$manager['id'];

switch ($_GET['action'] ?? '') {
    case 'list':
        jsonData(['items' => (object)FieldDrafts::all($mid)]);

    case 'save':
        $in = getInput();
        $key = (string)($in['key'] ?? '');
        if (trim($key) === '') jsonError('key required');
        jsonOk(['saved' => FieldDrafts::save($mid, $key, (string)($in['body'] ?? ''), (string)($in['base'] ?? ''))]);

    case 'clear':
        $in = getInput();
        FieldDrafts::clear($mid, (string)($in['key'] ?? ''));
        jsonOk();

    default:
        jsonError('Unknown action');
}
