import re

with open(r'c:\Users\Usuario\mercourier-web\wp-content\plugins\merc-table-customizer\admin\classes\class-shipment-table.php', 'r', encoding='utf-8') as f:
    content = f.read()

changes = []

# 1. updateGlobalCheckboxState: total/checked
changes.append((
    'updateGlobalCheckboxState total/checked',
    "\t\t\tvar total   = $('.wpcfe-shipments').length;\n\t\t\tvar checked = $('.wpcfe-shipments:checked').length;",
    "\t\t\tvar total   = $('.merc-ship-ui').length;\n\t\t\tvar checked = $('.merc-ship-ui:checked').length;"
))

# 2. updateGlobalCheckboxState per-card
changes.append((
    'updateGlobalCheckboxState per-card',
    "\t\t\t\t\tvar ct  = $c.find('.wpcfe-shipments').length;\n\t\t\t\t\tvar cc  = $c.find('.wpcfe-shipments:checked').length;",
    "\t\t\t\t\tvar ct  = $c.find('.merc-ship-ui').length;\n\t\t\t\t\tvar cc  = $c.find('.merc-ship-ui:checked').length;"
))

# 3. Clone step
changes.append((
    'clone step',
    "\t\t\t\t\ttiendas[tienda].push($row.clone());",
    "\t\t\t\t\tvar $cloned = $row.clone();\n\t\t\t\t\t$cloned.find('.wpcfe-shipments').removeClass('wpcfe-shipments').addClass('merc-ship-ui');\n\t\t\t\t\ttiendas[tienda].push($cloned);"
))

# 4. Replace table -> hide+insert
old_replace = (
    "\t\t\t\t// Reemplazar tabla y marcar wrapper para evitar procesamiento posterior\n"
    "\t\t\t\tconst $wrapper = $table.closest('#shipment-history-list') || $table.closest('.table-responsive') || $table.parent();\n"
    "\t\t\t\t\n"
    "\t\t\t\tif ($wrapper.length) {\n"
    "\t\t\t\t\t// Reemplazar contenido del wrapper para que otros scripts no procesen tablas antiguas\n"
    "\t\t\t\t\t$wrapper.html($accordion);\n"
    "\t\t\t\t\t$wrapper.addClass('merc-accordion-processed'); // Marcar para que otros scripts salten\n"
    "\t\t\t\t} else {\n"
    "\t\t\t\t\t$table.replaceWith($accordion);\n"
    "\t\t\t\t}"
)
new_replace = (
    "\t\t\t\t// Mantener tabla original OCULTA en el DOM -- WPCargo necesita '#shipment-list'\n"
    "\t\t\t\t// para sus handlers de bulk actions. El accordion se inserta antes de ella.\n"
    "\t\t\t\t$table.before($accordion);\n"
    "\t\t\t\t$table.hide().addClass('merc-accordion-processed').attr('aria-hidden', 'true');"
)
changes.append(('replace -> hide+insert', old_replace, new_replace))

# 5. Global select-all handler
old_gsa = (
    "\t\t\t\t$('#merc-select-all-global').off('change').on('change', function() {\n"
    "\t\t\t\t\tvar ck = $(this).prop('checked');\n"
    "\t\t\t\t\t$(this).prop('indeterminate', false);\n"
    "\t\t\t\t\t$('.merc-card-select-all').prop('checked', ck).prop('indeterminate', false);\n"
    "\t\t\t\t\t$('.wpcfe-shipments').prop('checked', ck);\n"
    "\t\t\t\t});"
)
new_gsa = (
    "\t\t\t\t$('#merc-select-all-global').off('change').on('change', function() {\n"
    "\t\t\t\t\tvar ck = $(this).prop('checked');\n"
    "\t\t\t\t\t$(this).prop('indeterminate', false);\n"
    "\t\t\t\t\t$('.merc-card-select-all').prop('checked', ck).prop('indeterminate', false);\n"
    "\t\t\t\t\t$('.merc-ship-ui').prop('checked', ck);\n"
    "\t\t\t\t\t$('#shipment-list .wpcfe-shipments').prop('checked', ck);\n"
    "\t\t\t\t});"
)
changes.append(('global select-all', old_gsa, new_gsa))

# 6. Bulk print collection
changes.append((
    'bulk print collection',
    "\t\t\t\t\tvar selected  = [];\n\t\t\t\t\t$('.wpcfe-shipments:checked').each(function() { selected.push($(this).val()); });",
    "\t\t\t\t\tvar selected  = [];\n\t\t\t\t\t$('.merc-ship-ui:checked').each(function() { selected.push($(this).val()); });"
))

# 7. Bulk print reset
changes.append((
    'bulk print reset',
    "\t\t\t\t\t\t\t\t\t\t$('.wpcfe-shipments, .merc-tienda-checkbox').prop('checked', false).prop('indeterminate', false);",
    "\t\t\t\t\t\t\t\t\t\t$('.merc-ship-ui, .wpcfe-shipments, .merc-tienda-checkbox').prop('checked', false).prop('indeterminate', false);"
))

# 8. Remove postAccordionSetup sections 3 and 4 (replace body up to closing brace)
import re
pattern = (
    r"\t\t\t\t// 3\. Asignación/Actualización masiva\.\n"
    r".*?"  # non-greedy
    r"\t\t\t}"  # closing brace of postAccordionSetup
)
m = re.search(pattern, content, re.DOTALL)
if m:
    old_s34 = m.group(0)
    new_s34 = "\t\t\t}"
    content = content.replace(old_s34, new_s34, 1)
    print('OK: remove sections 3+4 (regex)')
else:
    print('ERROR: sections 3+4 not found via regex')

# 9. Remove capture listener block from document.ready
cap_start = "\t\t\t\t// ── Actualización masiva: captura nativa (siempre activa) ────────────"
cap_end = "\t\t\t\tdocument.addEventListener('click', window._mercBulkUpdateCapture, true);\n\n"
idx_s = content.find(cap_start)
idx_e = content.find(cap_end)
if idx_s >= 0 and idx_e >= 0:
    content = content[:idx_s] + content[idx_e + len(cap_end):]
    print('OK: remove capture listener')
else:
    print(f'ERROR: capture listener not found (start={idx_s}, end={idx_e})')

# 10. Card select-all handler
old_card = (
    "\t\t\t\t$(document).on('change', '.merc-card-select-all', function() {\n"
    "\t\t\t\t\tvar $cb = $(this);\n"
    "\t\t\t\t\tvar isChecked = $cb.prop('checked');\n"
    "\t\t\t\t\t$cb.prop('indeterminate', false);\n"
    "\t\t\t\t\tvar $card = $cb.closest('.merc-tienda-card');\n"
    "\t\t\t\t\t$card.find('.wpcfe-shipments').prop('checked', isChecked);\n"
    "\t\t\t\t\tupdateGlobalCheckboxState();\n"
    "\t\t\t\t});"
)
new_card = (
    "\t\t\t\t$(document).on('change', '.merc-card-select-all', function() {\n"
    "\t\t\t\t\tvar $cb = $(this);\n"
    "\t\t\t\t\tvar isChecked = $cb.prop('checked');\n"
    "\t\t\t\t\t$cb.prop('indeterminate', false);\n"
    "\t\t\t\t\tvar $card = $cb.closest('.merc-tienda-card');\n"
    "\t\t\t\t\t$card.find('.merc-ship-ui').prop('checked', isChecked);\n"
    "\t\t\t\t\t$card.find('.merc-ship-ui').each(function() {\n"
    "\t\t\t\t\t\t$('#shipment-list .wpcfe-shipments[value=\"' + $(this).val() + '\"]').prop('checked', isChecked);\n"
    "\t\t\t\t\t});\n"
    "\t\t\t\t\tupdateGlobalCheckboxState();\n"
    "\t\t\t\t});"
)
changes.append(('card select-all', old_card, new_card))

# 11. Individual change handler
old_ind = (
    "\t\t\t\t// ── Sync encabezado al cambiar fila individual ──────────────────────\n"
    "\t\t\t\t$(document).on('change', '.wpcfe-shipments', function() {\n"
    "\t\t\t\t\tvar $card = $(this).closest('.merc-tienda-card');\n"
    "\t\t\t\t\tupdateGlobalCheckboxState();\n"
    "\t\t\t\t\tif (!$card.length) return;\n"
    "\t\t\t\t\tvar total   = $card.find('.wpcfe-shipments').length;\n"
    "\t\t\t\t\tvar checked = $card.find('.wpcfe-shipments:checked').length;\n"
    "\t\t\t\t\tvar allCk   = checked === total && total > 0;\n"
    "\t\t\t\t\tvar someCk  = checked > 0 && checked < total;\n"
    "\t\t\t\t\t$card.find('.merc-card-select-all').prop('checked', allCk).prop('indeterminate', someCk);\n"
    "\t\t\t\t});"
)
new_ind = (
    "\t\t\t\t// ── Sync encabezado al cambiar fila individual ──────────────────────\n"
    "\t\t\t\t$(document).on('change', '.merc-ship-ui', function() {\n"
    "\t\t\t\t\tvar id  = $(this).val();\n"
    "\t\t\t\t\tvar ck  = $(this).prop('checked');\n"
    "\t\t\t\t\t$('#shipment-list .wpcfe-shipments[value=\"' + id + '\"]').prop('checked', ck);\n"
    "\t\t\t\t\tvar $card = $(this).closest('.merc-tienda-card');\n"
    "\t\t\t\t\tupdateGlobalCheckboxState();\n"
    "\t\t\t\t\tif (!$card.length) return;\n"
    "\t\t\t\t\tvar total = $card.find('.merc-ship-ui').length;\n"
    "\t\t\t\t\tvar cnt   = $card.find('.merc-ship-ui:checked').length;\n"
    "\t\t\t\t\t$card.find('.merc-card-select-all')\n"
    "\t\t\t\t\t\t.prop('checked', cnt === total && total > 0)\n"
    "\t\t\t\t\t\t.prop('indeterminate', cnt > 0 && cnt < total);\n"
    "\t\t\t\t});"
)
changes.append(('individual change handler', old_ind, new_ind))

# Apply remaining changes
errors = []
for name, old, new in changes:
    if old in content:
        content = content.replace(old, new, 1)
        print(f'OK: {name}')
    else:
        errors.append(name)
        print(f'ERROR: {name}')

with open(r'c:\Users\Usuario\mercourier-web\wp-content\plugins\merc-table-customizer\admin\classes\class-shipment-table.php', 'w', encoding='utf-8') as f:
    f.write(content)

if errors:
    print(f'\nFailed: {errors}')
else:
    print('\nAll done!')
