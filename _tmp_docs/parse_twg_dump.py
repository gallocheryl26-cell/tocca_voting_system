import re
from pathlib import Path

p = Path(r"c:\Users\Rysha\OneDrive - ormoc.sti.ph\Desktop\u556809062_tocca_db.sql")
text = p.read_text(encoding="utf-8", errors="replace")

def block(table: str) -> str | None:
    m = re.search(
        rf"INSERT INTO `{table}`.*?VALUES\s*(.*?);",
        text,
        re.S,
    )
    return m.group(1) if m else None

for table in (
    "tbl_twg_member_scores",
    "tbl_twg_scores",
    "tbl_twg_entry_member_scores",
):
    b = block(table)
    if b is None:
        print(f"{table}: no INSERT (empty or missing)")
        continue
    n = len(re.findall(r"\(\d+,\d+", b))
    dates = sorted(set(re.findall(r"'(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})'", b)))
    print(f"{table}: {n} rows; dates {dates[0]} .. {dates[-1]}" if dates else f"{table}: {n} rows")

# active event
ev = re.search(r"INSERT INTO `tbl_events`.*?VALUES\s*(.*?);", text, re.S)
if ev:
    print("events sample:")
    print(ev.group(1)[:500])
