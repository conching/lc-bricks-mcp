<?php
/**
 * Unit test: BricksService global-variable and typography-scale writers store
 * names in Bricks' native bare format (no leading "--"), refuse collisions, and
 * repair legacy double-prefixed data. Options are held in memory; Bricks'
 * :root emitter is reproduced to prove no "----name" property is generated.
 *
 * @package LCBricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

// --- Minimal WordPress stubs -------------------------------------------------

$GLOBALS['opts'] = [];

function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function __( $s, $d = null ) { return $s; }
function is_wp_error( $x ) { return $x instanceof WP_Error; }

class WP_Error {
	public function __construct( public $code = '', public $message = '', public $data = null ) {}
}

require_once __DIR__ . '/../bootstrap-simple.php';

spl_autoload_register(
	static function ( string $class ): void {
		if ( str_starts_with( $class, 'LCBricksMCP\\' ) ) {
			$file = __DIR__ . '/../../includes/' . str_replace( '\\', '/', substr( $class, 12 ) ) . '.php';
			if ( is_file( $file ) ) {
				require_once $file;
			}
		}
	}
);

use LCBricksMCP\MCP\Services\BricksService;
// Same behaviour as Bricks 2.3.9 Assets::format_variables_as_css(): prepends "--" to every stored name.
function bricks_emit($vars){ $css=':root {'; foreach($vars as $v){ if(isset($v['name'],$v['value']) && $v['value']!=='') $css.="--{$v['name']}: {$v['value']};"; } return $css.'}'; }
$s = new BricksService();

// 1. Reported bug: create with bare and with -- name.
$a = $s->create_global_variable('brand-probe','7px');
$b = $s->create_global_variable('--brand-gap','1rem');
lc_assert($a['name']==='brand-probe' && $a['css_var']==='--brand-probe', 'create bare name stored bare, css_var --brand-probe');
lc_assert($b['name']==='brand-gap', 'create --name stored bare');
// 2. update with bare name keeps bare.
$u = $s->update_global_variable($a['id'], ['name'=>'brand-probe','value'=>'8px']);
lc_assert($u['name']==='brand-probe' && !isset($u['warning']), 'update same bare name: no re-prefix, no warning');
// 3. typography scale with --prefix.
$t = $s->create_typography_scale('Text', [['name'=>'h1','value'=>'56px'],['name'=>'body','value'=>'18px']], '--brand-text-');
lc_assert($t['prefix']==='brand-text-' && $t['variables'][0]['name']==='brand-text-h1', 'scale prefix + step names stored bare');
lc_assert($t['utility_classes'][0]['className']==='brand-text-*', 'default utility class brand-text-*');
$css = bricks_emit(get_option('bricks_global_variables'));
lc_assert(!str_contains($css,'----') && str_contains($css,'--brand-text-h1: 56px') && str_contains($css,'--brand-probe: 8px'), 'Bricks emitter output has no doubled prefix');
// Bricks STEP 4 utility class logic: str_replace(prefix,'',name).
$cat = get_option('bricks_global_variables_categories')[0];
$step = str_replace($cat['scale']['prefix'],'', 'brand-text-h1');
lc_assert($step==='h1', 'Bricks utility-class step extraction yields h1 -> .brand-text-h1 { font-size: var(--brand-text-h1) }');
lc_assert(!isset($s->get_global_variables()['warning']), 'list shows no legacy warning on clean data');

// 4. Legacy data (what 2.1.1 wrote) + repair.
$GLOBALS['opts'] = [
 'bricks_global_variables' => [
   ['id'=>'v1','name'=>'--brand-probe','value'=>'7px','category'=>''],
   ['id'=>'v2','name'=>'--brand-text-h1','value'=>'56px','category'=>'c1'],
   ['id'=>'v3','name'=>'ok-var','value'=>'1px','category'=>''],
 ],
 'bricks_global_variables_categories' => [ ['id'=>'c1','name'=>'Text','scale'=>['prefix'=>'--brand-text-'],'utilityClasses'=>[['className'=>'brand-text-*','cssProperty'=>'font-size']]] ],
];
$l = $s->get_global_variables();
lc_assert(($l['legacy_double_prefix']['variables']??0)===2 && ($l['legacy_double_prefix']['scale_prefixes']??0)===1, 'list flags 2 legacy vars + 1 legacy prefix');
$before = $GLOBALS['opts'];
$d = $s->repair_variable_names(true);
lc_assert($GLOBALS['opts']===$before && $d['renamed_count']===2 && $d['prefix_count']===1, 'dry run reports 2+1 and writes nothing');
$r = $s->repair_variable_names(false);
$css = bricks_emit(get_option('bricks_global_variables'));
lc_assert(!str_contains($css,'----') && get_option('bricks_global_variables_categories')[0]['scale']['prefix']==='brand-text-', 'apply repair fixes names and prefix');
lc_assert($s->repair_variable_names(false)['renamed_count']===0, 'repair is idempotent');

// 5. Legacy scale self-heal on update + new step.
$GLOBALS['opts']['bricks_global_variables_categories'][0]['scale']['prefix']='--brand-text-';
$GLOBALS['opts']['bricks_global_variables'][1]['name']='--brand-text-h1';
$up = $s->update_typography_scale('c1', null, [['name'=>'h2','value'=>'40px']]);
$names = array_column($up['variables'],'name');
lc_assert($up['prefix']==='brand-text-' && $names===['brand-text-h1','brand-text-h2'] && ($up['repaired']['variables']??0)===1, 'update self-heals legacy scale and adds bare step');
// 6. search with -- query finds bare.
lc_assert($s->search_global_variables('--brand-probe')['count']===1, 'search --brand-probe finds bare brand-probe');
// 7. prefix change renames.
$pc = $s->update_typography_scale('c1', null, null, 'brand-type-');
lc_assert(array_column($pc['variables'],'name')===['brand-type-h1','brand-type-h2'], 'prefix change renames steps');
// 8. Review finding 1: collisions after normalization.
$GLOBALS['opts'] = ['bricks_global_variables'=>[['id'=>'n1','name'=>'brand','value'=>'red','category'=>'']], 'bricks_global_variables_categories'=>[]];
$e = $s->create_global_variable('--brand','blue');
lc_assert(is_wp_error($e) && $e->code==='name_taken' && count(get_option('bricks_global_variables'))===1, 'create --brand over native brand -> name_taken, nothing written');
$bc = $s->batch_create_global_variables([['name'=>'x','value'=>'1'],['name'=>'--x','value'=>'2'],['name'=>'brand','value'=>'3']]);
lc_assert($bc['created_count']===1 && $bc['error_count']===2, 'batch rejects in-batch duplicate and existing name');
$x = $s->create_global_variable('other','1px');
$ue = $s->update_global_variable($x['id'], ['name'=>'--brand']);
lc_assert(is_wp_error($ue) && $ue->code==='name_taken', 'update rename onto existing name -> name_taken');
$te = $s->create_typography_scale('T', [['name'=>'brand','value'=>'1rem']], '');
lc_assert(is_wp_error($te), 'empty prefix rejected');
$GLOBALS['opts']['bricks_global_variables'][] = ['id'=>'n9','name'=>'t-h1','value'=>'1','category'=>''];
$before = $GLOBALS['opts'];
$te = $s->create_typography_scale('T', [['name'=>'h1','value'=>'1rem']], '--t-');
lc_assert(is_wp_error($te) && $te->code==='name_taken' && $GLOBALS['opts']===$before, 'scale create step colliding with existing var -> name_taken, nothing written');
// 9. Review finding 2: blocked scale via update.
$GLOBALS['opts'] = [
 'bricks_global_variables'=>[['id'=>'o','name'=>'text-h1','value'=>'1','category'=>''],['id'=>'s1','name'=>'--text-h1','value'=>'2','category'=>'c']],
 'bricks_global_variables_categories'=>[['id'=>'c','name'=>'T','scale'=>['prefix'=>'--text-']]]];
$before = $GLOBALS['opts'];
$ue = $s->update_typography_scale('c', null, [['name'=>'h2','value'=>'3']]);
lc_assert(is_wp_error($ue) && $ue->code==='legacy_name_conflict' && $GLOBALS['opts']===$before, 'update on conflicted legacy scale -> legacy_name_conflict, nothing written');
$rp = $s->repair_variable_names(false);
lc_assert($GLOBALS['opts']['bricks_global_variables_categories'][0]['scale']['prefix']==='--text-' && $rp['conflict_count']===2, 'repair_names keeps blocked prefix and reports 2 conflicts');
// 10. update step rename onto another var's name is refused.
$GLOBALS['opts'] = ['bricks_global_variables'=>[['id'=>'o','name'=>'t-big','value'=>'1','category'=>''],['id'=>'s1','name'=>'t-h1','value'=>'2','category'=>'c']],
 'bricks_global_variables_categories'=>[['id'=>'c','name'=>'T','scale'=>['prefix'=>'t-']]]];
$before = $GLOBALS['opts'];
$ue = $s->update_typography_scale('c', 'Renamed', [['id'=>'s1','name'=>'big']]);
lc_assert(is_wp_error($ue) && $ue->code==='name_taken' && $GLOBALS['opts']===$before, 'scale step rename onto existing var -> name_taken, nothing written (incl. category name)');
// 11. Review round 2: legacy update rename keeps a (legacy-specific) warning.
$GLOBALS['opts'] = ['bricks_global_variables'=>[['id'=>'L','name'=>'--brand','value'=>'red','category'=>'']], 'bricks_global_variables_categories'=>[]];
$lu = $s->update_global_variable('L', ['name'=>'brand']);
lc_assert($lu['name']==='brand' && str_contains($lu['warning']??'','var(----brand)'), 'legacy update repair warns about var(----brand)');
// 12. Legacy --var(--x) repairs to x.
$GLOBALS['opts'] = ['bricks_global_variables'=>[['id'=>'V','name'=>'--var(--brand)','value'=>'red','category'=>'']], 'bricks_global_variables_categories'=>[]];
$s->repair_variable_names(false);
lc_assert(get_option('bricks_global_variables')[0]['name']==='brand', 'repair turns --var(--brand) into brand');
// 13. Review round 3: warning on scale self-heal; css_var truthful for legacy entries.
$GLOBALS['opts'] = ['bricks_global_variables'=>[['id'=>'s','name'=>'--t-h1','value'=>'1','category'=>'c'],['id'=>'p','name'=>'--plain','value'=>'2','category'=>'']],
 'bricks_global_variables_categories'=>[['id'=>'c','name'=>'T','scale'=>['prefix'=>'--t-']]]];
$l = $s->get_global_variables();
lc_assert(($l['uncategorized'][0]['css_var']??'')==='----plain', 'list css_var shows doubled property for unrepaired legacy entry');
$h = $s->update_typography_scale('c', 'Renamed');
lc_assert(isset($h['warning']) && str_contains($h['warning'],'var(----prefix-step)') && $h['variables'][0]['css_var']==='--t-h1', 'label-only update of legacy scale repairs it and warns');

lc_test_done( 'VariableWritersTest' );
