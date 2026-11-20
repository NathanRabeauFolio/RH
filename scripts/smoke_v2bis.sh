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
trap 'echo "Smoke test failure near line $LINENO" >&2; tail -n 14 "$tmp/server.log" >&2' ERR
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
request -d action=save -d "csrf=$token" -d report_month=2026-11 --data-urlencode 'date[]=2026-10-25' --data-urlencode 'date[]=2026-10-26' --data-urlencode 'date[]=2026-11-01' --data-urlencode 'reason[]=Événement' --data-urlencode 'reason[]=Montage exposition' --data-urlencode 'reason[]=Autre' "$url/rest_work.php" > "$tmp/rest"
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
grep -Fq 'Novembre 2026' "$tmp/edited_rest"
# Another staff member cannot modify or delete Laura's record.
other() { curl -fsSL --max-time 20 -b "$tmp/other.cookies" -c "$tmp/other.cookies" "$@"; }
other_id="$(php -r '$c=require $argv[1];foreach($c["users"] as $u){if($u["role"]==="employee"&&$u["id"]!=="laura"&&password_verify(strtolower($u["first_name"]),$u["password_hash"])){echo $u["id"];exit;}}exit(1);' "$tmp/app/config.default.php")"
other -d action=login -d "user_id=$other_id" --data-urlencode "password=$(first_name "$other_id")" "$url/index.php" > "$tmp/other"
other_token="$(php -r '$s=file_get_contents($argv[1]);preg_match("/name=\"csrf\" value=\"([a-f0-9]{64})\"/",$s,$m);echo $m[1]??"";' "$tmp/other")"
other -d action=delete -d "csrf=$other_token" -d "id=$rest_id" "$url/rest_work.php" > "$tmp/blocked_rest"
grep -Fq 'Déclaration introuvable' "$tmp/blocked_rest"
# A late declaration belongs to exactly one selected reporting month.
request -d action=save -d "csrf=$token" -d report_month=2026-09 --data-urlencode 'date[]=2026-10-23' --data-urlencode 'reason[]=' "$url/rest_work.php" > "$tmp/badmonth"
grep -Fq 'Le mois RH doit être compris' "$tmp/badmonth"
# Legacy declarations are attributed to their creation month, without modifying stored files.
php -r '$path=$argv[1];$r=json_decode(file_get_contents($path),true);unset($r[1]["report_month"]);$r[1]["created_at"]="2026-11-20T12:00:00+01:00";file_put_contents($path,json_encode($r));' "$rfile"
admin_request "$url/export_transport_docx.php?month=2026-10" -o "$tmp/oct.docx"
unzip -tq "$tmp/oct.docx" >/dev/null
unzip -p "$tmp/oct.docx" word/document.xml > "$tmp/oct.xml"
admin_request "$url/export_transport_docx.php?month=2026-11" -o "$tmp/nov.docx"
unzip -tq "$tmp/nov.docx" >/dev/null
unzip -p "$tmp/nov.docx" word/document.xml > "$tmp/nov.xml"
admin_request "$url/export_transport_docx.php?month=2026-12" -o "$tmp/dec.docx"
unzip -p "$tmp/dec.docx" word/document.xml > "$tmp/dec.xml"
python3 - "$tmp/oct.xml" "$tmp/nov.xml" "$tmp/dec.xml" <<'PY'
import sys
import xml.etree.ElementTree as ET
ns = {"w": "http://schemas.openxmlformats.org/wordprocessingml/2006/main"}
def cells(row):
    return ["\n".join("".join(t.text or "" for t in p.findall(".//w:t", ns)) for p in c.findall("./w:p", ns)) for c in row.findall("./w:tc", ns)]
for path, has_rest in zip(sys.argv[1:], [False, True, False]):
    root = ET.parse(path).getroot()
    tables = root.findall(".//w:tbl", ns)
    assert len(tables) == 1, "Expected exactly one table in monthly RH Word"
    orient = root.find(".//w:pgSz", ns).get("{"+ns["w"]+"}orient")
    assert (orient == "landscape") == has_rest
    data = [cells(row) for row in tables[0].findall("./w:tr", ns)]
    assert len(data[0]) == (7 if has_rest else 6), data
    assert ("Jours de repos travaillés" in data[0]) == has_rest, data
    rows = {row[0]: row for row in data[1:]}
    assert len(rows) == 5, rows
    assert all(len(row) == len(data[0]) for row in rows.values()), rows
    assert "TOTAL REPOS TRAVAILLÉS" not in rows, rows
    text = " ".join(t.text or "" for t in root.findall(".//w:t", ns))
    assert "sans validation administrative" not in text
    assert "Aucune compensation" not in text
    assert "Total :" not in text
    for value in ["Samedi 24/10/2026", "Motif corrigé", "Lundi 26/10/2026", "Montage exposition", "Dimanche 01/11/2026", "Autre"]:
        assert (value in text) == has_rest, (value, text)
    if has_rest:
        assert rows["Laura"][-1].splitlines() == ["3 jours", "Samedi 24/10/2026", "Motif corrigé", "Lundi 26/10/2026", "Montage exposition", "Dimanche 01/11/2026", "Autre"], rows
        assert all(row[-1] == "—" for name, row in rows.items() if name != "Laura"), rows
        assert "19" in rows["Laura"], rows
PY
# Changing a report month moves the existing declaration, not a copy.
request -d action=update -d "csrf=$token" -d "id=$rest_id" -d date=2026-10-24 -d report_month=2026-10 --data-urlencode 'reason=Motif corrigé' "$url/rest_work.php?id=$rest_id" > "$tmp/move"
php -r '$r=json_decode(file_get_contents($argv[1]),true);exit(count($r)===3&&$r[0]["report_month"]==="2026-10"?0:1);' "$rfile"
admin_request "$url/export_transport_docx.php?month=2026-10" -o "$tmp/oct-moved.docx"
admin_request "$url/export_transport_docx.php?month=2026-11" -o "$tmp/nov-moved.docx"
unzip -p "$tmp/oct-moved.docx" word/document.xml > "$tmp/oct-moved.xml"
unzip -p "$tmp/nov-moved.docx" word/document.xml > "$tmp/nov-moved.xml"
grep -Fq 'Samedi 24/10/2026' "$tmp/oct-moved.xml"
if grep -Fq 'Samedi 24/10/2026' "$tmp/nov-moved.xml"; then exit 1; fi
grep -Fq 'Lundi 26/10/2026' "$tmp/nov-moved.xml"
request -d action=delete -d "csrf=$token" -d "id=$rest_id" "$url/rest_work.php" > "$tmp/deleted_rest"
grep -Fq 'Déclaration supprimée' "$tmp/deleted_rest"
php -r '$r=json_decode(file_get_contents($argv[1]),true);exit(count($r)===2?0:1);' "$rfile"
echo 'RH: jours de repos déclarés sans validation, édition, suppression, anti-doublon, permissions, CSRF, rattachement RH et tableau Word unique OK.'
