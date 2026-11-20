#!/usr/bin/env bash
# Exercise actual PHP routes with disposable data, never the live storage.
set -Eeuo pipefail
root="$(cd "$(dirname "$0")/.." && pwd)"
tmp="$(mktemp -d)"
pid=""
cleanup() {
  if [[ -n "$pid" ]]; then kill "$pid" 2>/dev/null || true; wait "$pid" 2>/dev/null || true; fi
  rm -rf -- "$tmp"
}
trap cleanup EXIT
cp -a "$root/app" "$root/public" "$tmp/"
mkdir "$tmp/sessions"
php -d session.save_path="$tmp/sessions" -S 127.0.0.1:18765 -t "$tmp/public" >"$tmp/server.log" 2>&1 &
pid=$!
url=http://127.0.0.1:18765
for n in {1..20}; do if curl -fsS "$url/health.php" >/dev/null 2>&1; then break; fi; sleep .3; done
curl -fsS "$url/" > "$tmp/login"
grep -Fq 'data-user-id="laura"' "$tmp/login"
grep -Fq 'styles.css?v=2bis' "$tmp/login"
# Use the existing temporary-password convention in the isolated fixture.
first_name() { php -r '$c=require $argv[1];foreach($c["users"] as $u){if($u["id"]===$argv[2]){echo strtolower($u["first_name"]);exit;}}exit(1);' "$tmp/app/config.default.php" "$1"; }
request() { curl -fsSL --max-time 20 -b "$tmp/employee.cookies" -c "$tmp/employee.cookies" "$@"; }
admin_request() { curl -fsSL --max-time 20 -b "$tmp/admin.cookies" -c "$tmp/admin.cookies" "$@"; }
request -d action=login -d user_id=laura --data-urlencode "password=$(first_name laura)" "$url/index.php" > "$tmp/employee"
grep -Fq 'Bonjour, Laura' "$tmp/employee"
for section in notes-de-frais titres-restaurant navigo jours-repos-travailles conges calendrier; do grep -Fq "id=\"$section\"" "$tmp/employee"; done
request --data-urlencode 'row_id[]=0' --data-urlencode 'row_id[]=1' --data-urlencode 'date[]=2026-11-18' --data-urlencode 'date[]=2026-11-19' --data-urlencode 'description[]=V2bis smoke A' --data-urlencode 'description[]=V2bis smoke B' --data-urlencode 'amount_ttc[]=24' --data-urlencode 'amount_ttc[]=12' --data-urlencode 'amount_ht[]=20' --data-urlencode 'amount_ht[]=10' --data-urlencode 'payment_mode[]=cb' --data-urlencode 'payment_mode[]=cash' "$url/save_expenses.php" > "$tmp/expense"
grep -Fq 'V2bis smoke A' "$tmp/expense"
grep -Fq 'V2bis smoke B' "$tmp/expense"
request -d month=2026-11 -d eligible_days=20 -d withdrawals=2 "$url/save_ticket.php" > "$tmp/tickets"
request -d month=2026-11 -d eligible_days=19 -d withdrawals=2 "$url/save_ticket.php" > "$tmp/tickets"
request -d month=2026-11 -d has_navigo=1 "$url/save_navigo.php" > "$tmp/navigo"
request --data-urlencode 'row_id[]=0' --data-urlencode 'leave_type[]=paid' --data-urlencode 'start_date[]=2026-11-25' --data-urlencode 'end_date[]=2026-11-27' "$url/save_leaves.php" > "$tmp/leaves"
grep -Fq 'Demandé' "$tmp/leaves"
php -r '
$root=$argv[1];
foreach(["expenses"=>2,"tickets"=>1,"navigo"=>1,"leaves"=>1] as $k=>$n){
 $v=json_decode(file_get_contents($root."/".$k.".json"),true);
 if(!is_array($v)||count($v)!==$n||array_filter($v,fn($r)=>$r["employee_id"]!=="laura")) exit(1);
}
$v=json_decode(file_get_contents($root."/tickets.json"),true);
if($v[0]["eligible_days"]!==19||$v[0]["withdrawals"]!==2)exit(1);
$v=json_decode(file_get_contents($root."/leaves.json"),true);
if($v[0]["status"]!=="pending"||$v[0]["estimated_days"]!==3)exit(1);
' "$tmp/storage/data"
record_id() { php -r '$v=json_decode(file_get_contents($argv[1]),true);echo $v[0]["id"];' "$tmp/storage/data/$1.json"; }
exp="$(record_id expenses)"
leave="$(record_id leaves)"
request "$url/edit.php?type=expenses&id=$exp" > "$tmp/edit"
grep -Fq 'V2bis smoke A' "$tmp/edit"
request -d date=2026-11-18 --data-urlencode 'description=V2bis edited' -d amount_ttc=24 -d amount_ht=20 -d payment_mode=cb "$url/edit.php?type=expenses&id=$exp" > "$tmp/edited"
grep -Fq 'V2bis edited' "$tmp/edited"
request "$url/export_notes_docx.php" -o "$tmp/notes.docx"
unzip -tq "$tmp/notes.docx" >/dev/null
request "$url/export_calendar_pdf.php?month=2026-11" -o "$tmp/calendar.pdf"
head -c 5 "$tmp/calendar.pdf" | grep -Fq '%PDF-'
request "$url/export_notes_docx.php?employee=nathan-r" > "$tmp/refused"
grep -Fq 'Accès refusé' "$tmp/refused"
admin_id="$(php -r '$c=require $argv[1];echo $c["admin_id"];' "$tmp/app/config.default.php")"
admin_request -d action=login --data-urlencode "user_id=$admin_id" --data-urlencode "password=$(first_name "$admin_id")" "$url/index.php" > "$tmp/admin"
grep -Fq 'Validation des congés' "$tmp/admin"
grep -Fq 'employee=laura' "$tmp/admin"
admin_request -d action=set_leave_status -d "id=$leave" -d status=approved -d admin_comment=Smoke "$url/admin.php?month=2026-11" > "$tmp/approval"
grep -Fq 'Accordé' "$tmp/approval"
request "$url/team_calendar.php?month=2026-11" > "$tmp/calendar"
grep -Fq 'Laura · Accordé' "$tmp/calendar"
admin_request "$url/export_transport_docx.php?month=2026-11" -o "$tmp/transport.docx"
unzip -tq "$tmp/transport.docx" >/dev/null
unzip -p "$tmp/transport.docx" word/document.xml > "$tmp/transport.xml"
grep -Fq 'Laura' "$tmp/transport.xml"
request "$url/delete.php?type=expenses&id=$exp" > "$tmp/deleted"
grep -Fq 'Élément supprimé' "$tmp/deleted"
php -r '$v=json_decode(file_get_contents($argv[1]),true);exit(count($v)===1?0:1);' "$tmp/storage/data/expenses.json"
echo 'RH smoke: login, batch expenses, ticket update, Navigo, leave, edit/delete, role checks, Word/PDF exports and Laura admin approval OK.'

# Days off worked: retroactive direct declaration, duplicates, edit, ownership, CSRF and monthly export.
# Dates are intentionally in two months; using historical dates independent of test run date.
token="$(php -r '$s=file_get_contents($argv[1]);preg_match("/name=\"csrf\" value=\"([a-f0-9]{64})\"/",$s,$m);echo $m[1]??"";' "$tmp/employee")"
test -n "$token"
request -d action=save -d "csrf=$token" --data-urlencode 'date[]=2026-10-25' --data-urlencode 'date[]=2026-10-26' --data-urlencode 'date[]=2026-11-01' --data-urlencode 'reason[]=Événement' --data-urlencode 'reason[]=' --data-urlencode 'reason[]=Autre' "$url/rest_work.php" > "$tmp/rest"
grep -Fq '3 jour(s) de repos travaillé(s) enregistré(s).' "$tmp/rest"
request -d action=save -d "csrf=$token" --data-urlencode 'date[]=2026-10-25' --data-urlencode 'reason[]=' "$url/rest_work.php" > "$tmp/duplicate"
grep -Fq 'Date déjà déclarée' "$tmp/duplicate"
request -d action=save -d "csrf=$token" --data-urlencode 'date[]=2999-01-01' --data-urlencode 'reason[]=' "$url/rest_work.php" > "$tmp/future"
grep -Fq 'ne peut pas être future' "$tmp/future"
request -d action=save -d csrf=incorrect --data-urlencode 'date[]=2026-10-24' --data-urlencode 'reason[]=' "$url/rest_work.php" > "$tmp/csrf"
grep -Fq 'Session expirée' "$tmp/csrf"
rfile="$tmp/storage/data/rest_work.json"
rest_id="$(php -r '$r=json_decode(file_get_contents($argv[1]),true);if(count($r)!==3)exit(1);echo $r[0]["id"];' "$rfile")"
request -d action=update -d "csrf=$token" -d "id=$rest_id" -d date=2026-10-24 --data-urlencode 'reason=Motif corrigé' "$url/rest_work.php?id=$rest_id" > "$tmp/edited_rest"
grep -Fq 'Motif corrigé' "$tmp/edited_rest"
# Another staff member cannot modify or delete Laura's record.
other() { curl -fsSL --max-time 20 -b "$tmp/other.cookies" -c "$tmp/other.cookies" "$@"; }
other -d action=login -d user_id=nathan-r --data-urlencode "password=$(first_name nathan-r)" "$url/index.php" > "$tmp/other"
other_token="$(php -r '$s=file_get_contents($argv[1]);preg_match("/name=\"csrf\" value=\"([a-f0-9]{64})\"/",$s,$m);echo $m[1]??"";' "$tmp/other")"
other -d action=delete -d "csrf=$other_token" -d "id=$rest_id" "$url/rest_work.php" > "$tmp/blocked_rest"
grep -Fq 'Déclaration introuvable' "$tmp/blocked_rest"
admin_request "$url/export_transport_docx.php?month=2026-10" -o "$tmp/oct.docx"
unzip -tq "$tmp/oct.docx" >/dev/null
unzip -p "$tmp/oct.docx" word/document.xml > "$tmp/oct.xml"
grep -Fq 'Jours de repos travaillés — Octobre 2026' "$tmp/oct.xml"
grep -Fq 'sans validation administrative' "$tmp/oct.xml"
grep -Fq 'Samedi 24/10/2026 ; Lundi 26/10/2026' "$tmp/oct.xml"
! grep -Fq 'Dimanche 01/11/2026' "$tmp/oct.xml"
admin_request "$url/export_transport_docx.php?month=2026-11" -o "$tmp/nov.docx"
unzip -p "$tmp/nov.docx" word/document.xml > "$tmp/nov.xml"
grep -Fq 'Dimanche 01/11/2026' "$tmp/nov.xml"
! grep -Fq 'Samedi 24/10/2026' "$tmp/nov.xml"
php -r '$xml=simplexml_load_file($argv[1]); if(!$xml)exit(1);$xml->registerXPathNamespace("w","http://schemas.openxmlformats.org/wordprocessingml/2006/main");$tables=$xml->xpath("//w:tbl");$table=end($tables);$table->registerXPathNamespace("w","http://schemas.openxmlformats.org/wordprocessingml/2006/main");foreach($table->xpath("./w:tr") as $row){$row->registerXPathNamespace("w","http://schemas.openxmlformats.org/wordprocessingml/2006/main");$values=array_map("strval",$row->xpath("./w:tc/w:p/w:r/w:t"));if($values[0]==="Laura"&&$values[2]!=="2")exit(1);if($values[0]==="TOTAL ÉQUIPE"&&end($values)!=="2")exit(1);}' "$tmp/oct.xml"
request -d action=delete -d "csrf=$token" -d "id=$rest_id" "$url/rest_work.php" > "$tmp/deleted_rest"
grep -Fq 'Déclaration supprimée' "$tmp/deleted_rest"
php -r '$r=json_decode(file_get_contents($argv[1]),true);exit(count($r)===2?0:1);' "$rfile"
echo 'RH: jours de repos déclarés sans validation, édition, suppression, anti-doublon, permissions, CSRF et export mensuel OK.'
