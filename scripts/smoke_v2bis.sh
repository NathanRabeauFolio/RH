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
for section in notes-de-frais titres-restaurant navigo conges calendrier; do grep -Fq "id=\"$section\"" "$tmp/employee"; done
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
