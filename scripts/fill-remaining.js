const fs = require('fs');
const path = require('path');
const rootDir = path.resolve(__dirname, '..');

const remaining = {
  fr_FR: {
    'Define macro actions as a JSON array. Supported action types: change_status, change_priority, assign_to, add_tag, remove_tag, add_reply, add_note, change_department.': "Definir les actions de la macro en tant que tableau JSON. Types d'action pris en charge : change_status, change_priority, assign_to, add_tag, remove_tag, add_reply, add_note, change_department.",
    'Enter actions as a JSON array. Each action should have "type" and "value" keys.': 'Saisir les actions sous forme de tableau JSON. Chaque action doit avoir les cles "type" et "value".',
    'Enter conditions as a JSON array. Each condition should have "field", "operator", and "value" keys.': 'Saisir les conditions sous forme de tableau JSON. Chaque condition doit avoir les cles "field", "operator" et "value".',
    'JSON array of actions. Supported types: %s': "Tableau JSON d'actions. Types pris en charge : %s",
    'JSON array of conditions. Supported fields: %s': 'Tableau JSON de conditions. Champs pris en charge : %s',
  },
  de_DE: {
    'Define macro actions as a JSON array. Supported action types: change_status, change_priority, assign_to, add_tag, remove_tag, add_reply, add_note, change_department.': 'Makro-Aktionen als JSON-Array definieren. Unterstuetzte Aktionstypen: change_status, change_priority, assign_to, add_tag, remove_tag, add_reply, add_note, change_department.',
    'Enter actions as a JSON array. Each action should have "type" and "value" keys.': 'Aktionen als JSON-Array eingeben. Jede Aktion sollte die Schluessel "type" und "value" haben.',
    'Enter conditions as a JSON array. Each condition should have "field", "operator", and "value" keys.': 'Bedingungen als JSON-Array eingeben. Jede Bedingung sollte die Schluessel "field", "operator" und "value" haben.',
    'Filter by status': 'Nach Status filtern',
    'JSON array of actions. Supported types: %s': 'JSON-Array von Aktionen. Unterstuetzte Typen: %s',
    'JSON array of conditions. Supported fields: %s': 'JSON-Array von Bedingungen. Unterstuetzte Felder: %s',
    'Last Run': 'Letzte Ausfuehrung',
    'Last Used': 'Zuletzt verwendet',
    'Please rate the support you received for this ticket.': 'Bitte bewerten Sie den Support, den Sie fuer dieses Ticket erhalten haben.',
    'Policy:': 'Richtlinie:',
    'Tag created successfully.': 'Tag erfolgreich erstellt.',
    'Tag deleted successfully.': 'Tag erfolgreich geloescht.',
    'Tag updated successfully.': 'Tag erfolgreich aktualisiert.',
    'You are viewing this ticket as a guest. Bookmark this page to access your ticket later.': 'Sie sehen dieses Ticket als Gast. Setzen Sie ein Lesezeichen auf diese Seite, um spaeter darauf zuzugreifen.',
  },
  ja: {
    'Define macro actions as a JSON array. Supported action types: change_status, change_priority, assign_to, add_tag, remove_tag, add_reply, add_note, change_department.': 'マクロアクションをJSON配列として定義します。サポートされるアクションタイプ：change_status, change_priority, assign_to, add_tag, remove_tag, add_reply, add_note, change_department。',
    'Enter actions as a JSON array. Each action should have "type" and "value" keys.': 'アクションをJSON配列として入力してください。各アクションには "type" と "value" キーが必要です。',
    'Enter conditions as a JSON array. Each condition should have "field", "operator", and "value" keys.': '条件をJSON配列として入力してください。各条件には "field"、"operator"、"value" キーが必要です。',
    'Filter by status': 'ステータスでフィルター',
    'JSON array of actions. Supported types: %s': 'アクションのJSON配列。サポートされるタイプ：%s',
    'JSON array of conditions. Supported fields: %s': '条件のJSON配列。サポートされるフィールド：%s',
    'Last Run': '最終実行',
    'Last Used': '最終使用',
    'Please rate the support you received for this ticket.': 'このチケットで受けたサポートを評価してください。',
    'Policy:': 'ポリシー：',
    'Tag created successfully.': 'タグが正常に作成されました。',
    'Tag deleted successfully.': 'タグが正常に削除されました。',
    'Tag updated successfully.': 'タグが正常に更新されました。',
    'You are viewing this ticket as a guest. Bookmark this page to access your ticket later.': 'ゲストとしてこのチケットを閲覧しています。後でアクセスするためにこのページをブックマークしてください。',
    'You do not have permission to manage API tokens.': 'APIトークンを管理する権限がありません。',
    'SLA Compliance Rate': 'SLA遵守率',
  },
  pt_BR: {
    'Define macro actions as a JSON array. Supported action types: change_status, change_priority, assign_to, add_tag, remove_tag, add_reply, add_note, change_department.': 'Defina as acoes da macro como um array JSON. Tipos de acao suportados: change_status, change_priority, assign_to, add_tag, remove_tag, add_reply, add_note, change_department.',
    'Enter actions as a JSON array. Each action should have "type" and "value" keys.': 'Insira as acoes como um array JSON. Cada acao deve ter as chaves "type" e "value".',
    'Enter conditions as a JSON array. Each condition should have "field", "operator", and "value" keys.': 'Insira as condicoes como um array JSON. Cada condicao deve ter as chaves "field", "operator" e "value".',
    'Filter by status': 'Filtrar por status',
    'JSON array of actions. Supported types: %s': 'Array JSON de acoes. Tipos suportados: %s',
    'JSON array of conditions. Supported fields: %s': 'Array JSON de condicoes. Campos suportados: %s',
    'Last Run': 'Ultima execucao',
    'Last Used': 'Ultimo uso',
    'Please rate the support you received for this ticket.': 'Por favor, avalie o suporte que voce recebeu para este chamado.',
    'Policy:': 'Politica:',
    'Tag created successfully.': 'Tag criada com sucesso.',
    'Tag deleted successfully.': 'Tag excluida com sucesso.',
    'Tag updated successfully.': 'Tag atualizada com sucesso.',
    'You are viewing this ticket as a guest. Bookmark this page to access your ticket later.': 'Voce esta visualizando este chamado como visitante. Salve esta pagina nos favoritos para acessar seu chamado depois.',
  },
};

for (const [locale, dict] of Object.entries(remaining)) {
  const poPath = path.join(rootDir, 'languages', `escalated-${locale}.po`);
  let content = fs.readFileSync(poPath, 'utf8');
  let updated = 0;

  for (const [key, value] of Object.entries(dict)) {
    const escapedKey = key.replace(/\\/g, '\\\\').replace(/"/g, '\\"');
    const escapedValue = value.replace(/\\/g, '\\\\').replace(/"/g, '\\"');
    const searchStr = `msgid "${escapedKey}"\nmsgstr ""`;
    const replaceStr = `msgid "${escapedKey}"\nmsgstr "${escapedValue}"`;

    if (content.includes(searchStr)) {
      content = content.replace(searchStr, replaceStr);
      updated++;
    }
  }

  fs.writeFileSync(poPath, content);
  console.log(`${locale}: filled ${updated} remaining translations`);
}
