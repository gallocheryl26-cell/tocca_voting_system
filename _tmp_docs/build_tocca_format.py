# -*- coding: utf-8 -*-
"""PRACTICOM capstone using TOCCA reference styles, pagination, and structure."""
from copy import deepcopy
from pathlib import Path
from zipfile import ZipFile

from docx import Document
from docx.enum.section import WD_ORIENT, WD_SECTION
from docx.enum.table import WD_TABLE_ALIGNMENT
from docx.enum.text import WD_ALIGN_PARAGRAPH, WD_LINE_SPACING, WD_TAB_ALIGNMENT
from docx.oxml import OxmlElement, parse_xml
from docx.oxml.ns import nsmap, qn
from docx.shared import Cm, Inches, Pt, Twips, Emu

REF = Path(r"c:\Users\Rysha\OneDrive - ormoc.sti.ph\Documents\TOCCA Capstone Final Document Revision.docx")
OUT = Path(r"c:\Users\Rysha\Downloads\Capstone proposal 1 ROMO GROUP (revised) CLEAN.docx")
MEDIA = Path(r"c:\xampp\htdocs\TOCCA_RECENT_NEWEST_2\_tmp_docs\proposal_media\media")

TITLE = (
    "PRACTICOM: A Digital Practicum and Internship Deployment "
    "and Monitoring System for STI College Ormoc"
)
PROPONENTS = [
    "Angelo Christian G. Aragon",
    "John Lloyd B. Gajol",
    "Jude Q. Mojado",
    "Jose Andrew Miguel P. Romo",
    "Stanley Argy M. Socorin",
]
DATE = "May 15, 2026"


def tnr(run, size=None, bold=None, italic=None):
    run.font.name = "Times New Roman"
    rPr = run._element.get_or_add_rPr()
    rFonts = rPr.find(qn("w:rFonts"))
    if rFonts is None:
        rFonts = OxmlElement("w:rFonts")
        rPr.append(rFonts)
    rFonts.set(qn("w:ascii"), "Times New Roman")
    rFonts.set(qn("w:hAnsi"), "Times New Roman")
    rFonts.set(qn("w:cs"), "Times New Roman")
    rFonts.set(qn("w:eastAsia"), "Times New Roman")
    if size is not None:
        run.font.size = Pt(size)
    if bold is not None:
        run.bold = bold
    if italic is not None:
        run.italic = italic


def add_p(doc, text, style="Normal", *, align=None, bold=None, italic=None, size=None, space_before=None, space_after=None, line=None):
    p = doc.add_paragraph(style=style)
    if align is not None:
        p.alignment = align
    pf = p.paragraph_format
    if space_before is not None:
        pf.space_before = Pt(space_before)
    if space_after is not None:
        pf.space_after = Pt(space_after)
    if line is not None:
        pf.line_spacing = line
    run = p.add_run(text)
    tnr(run, size=size, bold=bold, italic=italic)
    return p


def add_mixed(doc, parts, style="Normal", *, align=None):
    p = doc.add_paragraph(style=style)
    if align is not None:
        p.alignment = align
    for text, bold, italic in parts:
        run = p.add_run(text)
        tnr(run, bold=bold, italic=italic)
    return p


def body(doc, text):
    return add_p(doc, text, "Body of Research", align=WD_ALIGN_PARAGRAPH.JUSTIFY, bold=False, size=12)


def h1(doc, text):
    return add_p(doc, text, "Heading 1")


def h2(doc, text):
    return add_p(doc, text, "Heading 2")


def h3(doc, text):
    return add_p(doc, text, "Heading 3")


def objective(doc, statement, pertains):
    add_p(doc, statement, "List Paragraph", align=WD_ALIGN_PARAGRAPH.JUSTIFY, bold=True, size=12)
    add_p(doc, pertains, "List Paragraph", align=WD_ALIGN_PARAGRAPH.JUSTIFY, bold=False, size=12)


def page_break(doc):
    p = doc.add_paragraph()
    run = p.add_run()
    br = OxmlElement("w:br")
    br.set(qn("w:type"), "page")
    run._r.append(br)


def set_margins(section, left=1.5, right=1.0, top=1.0, bottom=1.0, header=0.5, footer=0.5):
    section.page_width = Inches(8.5)
    section.page_height = Inches(11)
    section.orientation = WD_ORIENT.PORTRAIT
    section.left_margin = Inches(left)
    section.right_margin = Inches(right)
    section.top_margin = Inches(top)
    section.bottom_margin = Inches(bottom)
    section.header_distance = Inches(header)
    section.footer_distance = Inches(footer)


def set_pg_num(section, fmt=None, start=None):
    sectPr = section._sectPr
    for el in sectPr.findall(qn("w:pgNumType")):
        sectPr.remove(el)
    el = OxmlElement("w:pgNumType")
    if fmt:
        el.set(qn("w:fmt"), fmt)
    if start is not None:
        el.set(qn("w:start"), str(start))
    sectPr.append(el)


def set_title_page(section, on=True):
    sectPr = section._sectPr
    for el in sectPr.findall(qn("w:titlePg")):
        sectPr.remove(el)
    if on:
        el = OxmlElement("w:titlePg")
        sectPr.append(el)


def add_page_field(paragraph):
    run = paragraph.add_run()
    tnr(run, size=12, bold=False)
    fld1 = OxmlElement("w:fldChar")
    fld1.set(qn("w:fldCharType"), "begin")
    run._r.append(fld1)
    run2 = paragraph.add_run()
    tnr(run2, size=12, bold=False)
    instr = OxmlElement("w:instrText")
    instr.set(qn("xml:space"), "preserve")
    instr.text = " PAGE "
    run2._r.append(instr)
    run3 = paragraph.add_run()
    tnr(run3, size=12, bold=False)
    fld2 = OxmlElement("w:fldChar")
    fld2.set(qn("w:fldCharType"), "end")
    run3._r.append(fld2)


def setup_footer(section, include_label=True):
    footer = section.footer
    footer.is_linked_to_previous = False
    for p in list(footer.paragraphs):
        p.clear()
    p = footer.paragraphs[0] if footer.paragraphs else footer.add_paragraph()
    p.alignment = WD_ALIGN_PARAGRAPH.CENTER
    if include_label:
        run = p.add_run("STI College Ormoc")
        tnr(run, size=12, bold=False)
        p2 = footer.add_paragraph()
        p2.alignment = WD_ALIGN_PARAGRAPH.CENTER
        add_page_field(p2)
    else:
        add_page_field(p)
    section.header.is_linked_to_previous = False
    for hp in section.header.paragraphs:
        hp.clear()


def add_picture(doc, name, width=6.0):
    path = MEDIA / name
    if not path.exists():
        return
    p = doc.add_paragraph(style="Normal")
    p.alignment = WD_ALIGN_PARAGRAPH.CENTER
    run = p.add_run()
    run.add_picture(str(path), width=Inches(width))
    tnr(run, bold=False)


def caption(doc, text):
    add_p(doc, text, "Body of Research", align=WD_ALIGN_PARAGRAPH.CENTER, italic=True, bold=False, size=12)


def borderless(table):
    tbl = table._tbl
    tblPr = tbl.tblPr if tbl.tblPr is not None else OxmlElement("w:tblPr")
    borders = OxmlElement("w:tblBorders")
    for edge in ("top", "left", "bottom", "right", "insideH", "insideV"):
        el = OxmlElement(f"w:{edge}")
        el.set(qn("w:val"), "nil")
        borders.append(el)
    tblPr.append(borders)


def set_cell(cell, text, *, bold=False, center=True, size=12):
    cell.text = ""
    p = cell.paragraphs[0]
    p.alignment = WD_ALIGN_PARAGRAPH.CENTER if center else WD_ALIGN_PARAGRAPH.LEFT
    run = p.add_run(text)
    tnr(run, size=size, bold=bold)


def import_tocca_styles(doc):
    xml = ZipFile(REF).read("word/styles.xml")
    new_styles = parse_xml(xml)
    el = doc.styles.element
    el.clear()
    for k, v in new_styles.attrib.items():
        el.set(k, v)
    for child in list(new_styles):
        el.append(child)
    # Force Times New Roman on Normal
    normal = None
    for st in doc.styles.element.findall(qn("w:style")):
        sid = st.get(qn("w:styleId"))
        if sid == "Normal":
            normal = st
            break
    if normal is not None:
        rPr = normal.find(qn("w:rPr"))
        if rPr is None:
            rPr = OxmlElement("w:rPr")
            normal.append(rPr)
        rFonts = rPr.find(qn("w:rFonts"))
        if rFonts is None:
            rFonts = OxmlElement("w:rFonts")
            rPr.insert(0, rFonts)
        rFonts.set(qn("w:ascii"), "Times New Roman")
        rFonts.set(qn("w:hAnsi"), "Times New Roman")
        rFonts.set(qn("w:cs"), "Times New Roman")
        rFonts.set(qn("w:eastAsia"), "Times New Roman")


def toc_table(doc):
    rows = [
        ("", "Page"),
        ("Title Page", "I"),
        ("Endorsement form for Oral Defense", "II"),
        ("Abstract", "III"),
        ("Approval Sheet", "IV"),
        ("Acknowledgments", "V"),
        ("Table of Contents", "VI"),
        ("Introduction", ""),
        ("     Project Context", "1"),
        ("     Purpose and Description", ""),
        ("     Objectives", ""),
        ("     Scope and Limitations", ""),
        ("     Review of Related Literature/Studies/Systems", ""),
        ("Methodology", ""),
        ("     Technical Background", ""),
        ("     Requirements Analysis", ""),
        ("     Requirements Documentation", ""),
        ("     Design of Software, System, Product, and/or Processes", ""),
        ("     Development", ""),
        ("Results and Discussion", ""),
        ("     Testing", ""),
        ("     Description of Prototype", ""),
        ("     Implementation Plan", ""),
        ("     Implementation Results", ""),
        ("Conclusion", ""),
        ("References", ""),
        ("Appendices", ""),
        ("     Resource Persons", ""),
        ("     Relevant Source Code", ""),
        ("     Personal Technical Vitae", ""),
    ]
    table = doc.add_table(rows=len(rows), cols=2)
    table.alignment = WD_TABLE_ALIGNMENT.CENTER
    table.autofit = True
    for i, (left, right) in enumerate(rows):
        c0, c1 = table.rows[i].cells
        set_cell(c0, left, bold=(i == 0 or not left.startswith(" ")), center=False, size=12)
        set_cell(c1, right, bold=False, center=True, size=12)
        if i == 0:
            set_cell(c0, "", bold=False, center=False)
            set_cell(c1, "Page", bold=True, center=True, size=12)
    return table


def add_cv(doc, name, address, email, phone, education, affiliations, skills):
    page_break(doc)
    add_p(doc, "Curriculum Vitae", "Normal", size=12, bold=True)
    add_p(doc, name, "Normal", size=16, bold=False)
    add_p(doc, address, "Normal", size=12, bold=False)
    add_p(doc, email, "Normal", size=12, bold=False)
    add_p(doc, phone, "Normal", size=12, bold=False)
    add_p(doc, "EDUCATIONAL BACKGROUND", "Normal", size=11, bold=False, align=WD_ALIGN_PARAGRAPH.LEFT)
    t = doc.add_table(rows=1 + len(education), cols=3)
    t.style = "Table Grid"
    set_cell(t.rows[0].cells[0], "Level", bold=True, center=True, size=11)
    set_cell(t.rows[0].cells[1], "Inclusive Dates", bold=True, center=True, size=11)
    set_cell(t.rows[0].cells[2], "Name of school/Institution", bold=True, center=True, size=11)
    for i, row in enumerate(education, start=1):
        for j, val in enumerate(row):
            set_cell(t.rows[i].cells[j], val, bold=False, center=False, size=11)
    add_p(doc, "AFFILIATIONS", "Normal", size=11, bold=False, align=WD_ALIGN_PARAGRAPH.JUSTIFY)
    t2 = doc.add_table(rows=1 + len(affiliations), cols=3)
    t2.style = "Table Grid"
    set_cell(t2.rows[0].cells[0], "Inclusive Dates", bold=True, center=True, size=11)
    set_cell(t2.rows[0].cells[1], "Name of Organization", bold=True, center=True, size=11)
    set_cell(t2.rows[0].cells[2], "Position", bold=True, center=True, size=11)
    for i, row in enumerate(affiliations, start=1):
        for j, val in enumerate(row):
            set_cell(t2.rows[i].cells[j], val, bold=False, center=False, size=11)
    add_p(doc, "SKILLS", "Normal", size=11, bold=False, align=WD_ALIGN_PARAGRAPH.JUSTIFY)
    t3 = doc.add_table(rows=1 + len(skills), cols=3)
    t3.style = "Table Grid"
    set_cell(t3.rows[0].cells[0], "Skills", bold=True, center=True, size=11)
    set_cell(t3.rows[0].cells[1], "Level of Competency", bold=True, center=True, size=11)
    set_cell(t3.rows[0].cells[2], "Date acquired", bold=True, center=True, size=11)
    for i, row in enumerate(skills, start=1):
        for j, val in enumerate(row):
            set_cell(t3.rows[i].cells[j], val, bold=False, center=False, size=11)


def build():
    doc = Document()
    import_tocca_styles(doc)

    section = doc.sections[0]
    set_margins(section, left=1.5, right=1.0, top=1.0, bottom=1.0, header=0.5, footer=0.281)
    section.different_first_page_header_footer = True
    set_title_page(section, True)
    set_pg_num(section, fmt="lowerRoman", start=1)
    setup_footer(section, include_label=True)
    # first-page footer empty (title page)
    fp_footer = section.first_page_footer
    fp_footer.is_linked_to_previous = False
    for p in fp_footer.paragraphs:
        p.clear()

    # ----- TITLE PAGE -----
    add_p(doc, TITLE.upper(), "Title", size=14, bold=True)
    add_p(doc, "A Capstone Project", "Normal", bold=True, size=12)
    add_p(doc, "Presented to the Faculty of the", "Normal", bold=True, size=12)
    add_p(doc, "Information and Communications Technology Program", "Normal", bold=True, size=12)
    p = add_p(doc, "STI College Ormoc", "Normal", bold=True, size=12)
    p.paragraph_format.line_spacing = 5.0
    add_p(doc, "In Partial Fulfilment", "Normal", bold=True, size=12)
    add_p(doc, "of the Requirements for the Degree", "Normal", bold=True, size=12)
    p = add_p(doc, "Bachelor of Science in Information Technology", "Normal", bold=True, size=12)
    p.paragraph_format.line_spacing = 5.0
    add_p(doc, "", "Normal")
    add_p(doc, "", "Normal")
    for name in PROPONENTS:
        add_p(doc, name, "Normal", bold=True, size=12)
    for _ in range(5):
        add_p(doc, "", "Normal")
    add_p(doc, DATE, "Normal", bold=True, size=12)

    page_break(doc)

    # ----- ENDORSEMENT -----
    add_p(doc, "ENDORSEMENT FORM FOR ORAL DEFENSE", "Normal", bold=True, size=12)
    add_p(doc, "", "Normal")
    add_mixed(
        doc,
        [("TITLE OF RESEARCH:  ", True, False), (TITLE, True, False)],
        style="Normal",
        align=WD_ALIGN_PARAGRAPH.LEFT,
    )
    add_p(doc, "", "Normal")
    add_mixed(
        doc,
        [("NAME OF PROPONENTS:", True, False), ("\t" + PROPONENTS[0], True, False)],
        style="Normal",
        align=WD_ALIGN_PARAGRAPH.JUSTIFY,
    )
    for name in PROPONENTS[1:]:
        add_p(doc, name, "Normal", bold=True, size=12)
    add_p(doc, "", "Normal")
    add_p(doc, "In Partial Fulfilment of the Requirements", "Normal", bold=False, size=12)
    add_p(doc, "for the degree Bachelor of Science in Information Technology", "Normal", bold=False, size=12)
    add_p(doc, "has been examined and is recommended for Oral Defense.", "Normal", bold=False, size=12)
    add_p(doc, "", "Normal")
    add_p(doc, "ENDORSED BY:", "Normal", bold=True, size=12)
    add_p(doc, "", "Normal")
    add_p(doc, "", "Normal")
    add_p(doc, "Elizabeth L. Dumaran", "Normal", bold=False, size=12)
    add_p(doc, "Capstone Project Adviser", "Normal", bold=True, size=12)
    add_p(doc, "", "Normal")
    add_p(doc, "APPROVED FOR ORAL DEFENSE:", "Normal", bold=True, size=12)
    add_p(doc, "", "Normal")
    add_p(doc, "", "Normal")
    add_p(doc, "Rona Mira B. Lucañas", "Normal", bold=False, size=12)
    add_p(doc, "Capstone Project Coordinator", "Normal", bold=True, size=12)
    add_p(doc, "", "Normal")
    add_p(doc, "NOTED BY:", "Normal", bold=True, size=12)
    add_p(doc, "", "Normal")
    add_p(doc, "", "Normal")
    add_p(doc, "Rona Mira B. Lucañas", "Normal", bold=False, size=12)
    add_p(doc, "Program Head", "Normal", bold=True, size=12)
    add_p(doc, "", "Normal")
    add_p(doc, DATE, "Normal", bold=True, size=12)

    page_break(doc)

    # ----- APPROVAL SHEET -----
    h1(doc, "APPROVAL SHEET")
    names = (
        "Angelo Christian G. Aragon, John Lloyd B. Gajol, Jude Q. Mojado, "
        "Jose Andrew Miguel P. Romo, and Stanley Argy M. Socorin"
    )
    add_mixed(
        doc,
        [
            ("This capstone project titled ", False, False),
            (TITLE, True, False),
            (", prepared and submitted by ", False, False),
            (names, False, False),
            (
                ", in partial fulfillment of the requirements for the degree of Bachelor of Science in Information Technology, has been examined and is recommended for acceptance and approval.",
                False,
                False,
            ),
        ],
        style="Normal",
        align=WD_ALIGN_PARAGRAPH.JUSTIFY,
    )
    add_p(doc, "", "Normal")
    add_p(doc, "Elizabeth L. Dumaran", "Normal", bold=True, size=12, align=WD_ALIGN_PARAGRAPH.JUSTIFY)
    add_p(doc, "Capstone Project Adviser", "Normal", bold=False, size=12, align=WD_ALIGN_PARAGRAPH.JUSTIFY)
    add_p(doc, "", "Normal")
    add_p(doc, "Accepted and approved by the Capstone Project Review Panel", "Normal", bold=False, size=12)
    add_p(doc, "in partial fulfillment of the requirements for the degree of", "Normal", bold=False, size=12)
    add_p(doc, "Bachelor of Science in Information Technology", "Normal", bold=False, size=12)

    t = doc.add_table(rows=2, cols=2)
    t.alignment = WD_TABLE_ALIGNMENT.CENTER
    borderless(t)
    set_cell(t.cell(0, 0), "Engr. Sheena Joy S. Muyuela", bold=False)
    set_cell(t.cell(0, 1), "Rona Mira B. Lucañas", bold=False)
    set_cell(t.cell(1, 0), "Panel Member", bold=True)
    set_cell(t.cell(1, 1), "Panel Member", bold=True)

    add_p(doc, "", "Normal")
    add_p(doc, "Joseph Nonel Bautista", "Normal", bold=False, size=12)
    add_p(doc, "Lead Panelist", "Normal", bold=True, size=12)
    add_p(doc, "Noted:", "Normal", bold=True, size=12)

    t2 = doc.add_table(rows=2, cols=2)
    t2.alignment = WD_TABLE_ALIGNMENT.CENTER
    borderless(t2)
    set_cell(t2.cell(0, 0), "Elizabeth L. Dumaran", bold=True)
    set_cell(t2.cell(0, 1), "Rona Mira B. Lucañas", bold=True)
    set_cell(t2.cell(1, 0), "Capstone Project Coordinator", bold=False)
    set_cell(t2.cell(1, 1), "Program Head", bold=False)
    add_p(doc, "", "Normal")
    add_p(doc, DATE, "Normal", bold=True, size=12)

    page_break(doc)

    # ----- ACKNOWLEDGEMENTS -----
    h1(doc, "Acknowledgements")
    add_p(
        doc,
        "First and foremost, we would like to thank God Almighty for the wisdom, strength, and guidance He has given us throughout the journey of completing this capstone project. Without His grace, this achievement would not have been possible.",
        "Normal (Web)",
        align=WD_ALIGN_PARAGRAPH.JUSTIFY,
        bold=False,
        size=12,
    )
    add_p(
        doc,
        "We would like to sincerely thank our adviser, Ms. Elizabeth L. Dumaran, for her continuous guidance, encouragement, and recognition of our efforts, which greatly helped us in accomplishing this study. We also acknowledge Ms. Rona Mira B. Lucañas, Capstone Project Coordinator and Program Head, for her support in aligning this project with the requirements of the Information and Communications Technology program of STI College Ormoc.",
        "Normal (Web)",
        align=WD_ALIGN_PARAGRAPH.JUSTIFY,
        bold=False,
        size=12,
    )
    add_p(
        doc,
        "We deeply appreciate the panel members, Engr. Sheena Joy S. Muyuela and Mr. Joseph Nonel Bautista, together with Ms. Rona Mira B. Lucañas, for their constructive feedback, insightful suggestions, and encouragement which helped us enhance and improve the quality of this project.",
        "Normal (Web)",
        align=WD_ALIGN_PARAGRAPH.JUSTIFY,
        bold=False,
        size=12,
    )
    add_p(
        doc,
        "Our sincere appreciation also goes to the On-the-Job Training coordinators and industry partners of STI College Ormoc for giving us the opportunity to study the actual practicum deployment process and for the knowledge, guidance, and support they generously shared with us. Their contributions have been vital in building and shaping PRACTICOM.",
        "Normal (Web)",
        align=WD_ALIGN_PARAGRAPH.JUSTIFY,
        bold=False,
        size=12,
    )
    add_p(
        doc,
        "Finally, we express our deepest gratitude to our families and friends for their unwavering support, encouragement, and understanding during the challenging moments of this journey. Their love and motivation gave us the strength to persevere and achieve our goal.",
        "Normal (Web)",
        align=WD_ALIGN_PARAGRAPH.JUSTIFY,
        bold=False,
        size=12,
    )

    page_break(doc)

    # ----- ABSTRACT -----
    h1(doc, "Abstract")
    add_mixed(
        doc,
        [("Title of research:  ", False, False), (TITLE, True, False)],
        style="Abstract",
        align=WD_ALIGN_PARAGRAPH.JUSTIFY,
    )
    add_mixed(
        doc,
        [("Researchers:\t", False, False), (PROPONENTS[0], False, False)],
        style="Normal",
        align=WD_ALIGN_PARAGRAPH.JUSTIFY,
    )
    for name in PROPONENTS[1:]:
        add_p(doc, name, "Normal", bold=False, size=12, align=WD_ALIGN_PARAGRAPH.JUSTIFY)
    add_mixed(
        doc,
        [("Degree:\t", False, False), ("Bachelor of Science in Information Technology", False, False)],
        style="Normal",
        align=WD_ALIGN_PARAGRAPH.LEFT,
    )
    add_mixed(
        doc,
        [("Date of Completion:\t", False, False), ("May 2026", False, False)],
        style="Normal",
        align=WD_ALIGN_PARAGRAPH.LEFT,
    )
    add_mixed(
        doc,
        [
            ("Keywords:\t", False, False),
            (
                "Practicum Management System, Internship Deployment, Student Monitoring, Company Partner Portal, School Coordinator Dashboard, Progress Tracking, Attendance Monitoring, Evaluation System, Digital Reports, STI College Ormoc, Student Placement, Industry Partnership, Academic Monitoring, Role-Based Access, Web and Mobile System",
                False,
                False,
            ),
        ],
        style="Normal",
        align=WD_ALIGN_PARAGRAPH.LEFT,
    )
    add_p(
        doc,
        "This capstone project presents the design and development of PRACTICOM, a Digital Practicum and Internship Deployment and Monitoring System for STI College Ormoc. The study addresses existing challenges in On-the-Job Training coordination across the BSIT, BSTM, and BSHM programs, which are commonly handled through manual, paper-based, and spreadsheet methods. These traditional practices often result in delayed application processing, inconsistent hour tracking, limited coordination among students, host companies, and school coordinators, and increased administrative workload.",
        "Normal (Web)",
        align=WD_ALIGN_PARAGRAPH.JUSTIFY,
        bold=False,
        size=12,
    )
    add_p(
        doc,
        "The primary objective of the project is to create a centralized web and mobile-based platform that streamlines the internship and practicum deployment process. Students authenticate through institutional Microsoft Office 365 accounts, create profiles, upload required documents, search for course-matched internship opportunities, and apply based on program requirements and required training hours. Partner organizations can post placement opportunities, review applications, and validate attendance, while administrators oversee verification, deployment, documents, and reporting.",
        "Normal (Web)",
        align=WD_ALIGN_PARAGRAPH.JUSTIFY,
        bold=False,
        size=12,
    )
    add_p(
        doc,
        "The system incorporates role-based access control to ensure appropriate functionality for students, coordinators, super administrators, and industry partners. It also includes document submission and verification, attendance logging with optional facial recognition, and tracking and reporting functions to assist the school in monitoring student placement status and practicum progress. Hours are measured against program requirements of 486 hours for BSIT and 600 hours for BSTM and BSHM.",
        "Normal (Web)",
        align=WD_ALIGN_PARAGRAPH.JUSTIFY,
        bold=False,
        size=12,
    )
    add_p(
        doc,
        "Validation and testing were conducted using representative student, coordinator, and company workflows of STI College Ormoc. Findings show that the system reduces administrative workload and strengthens record accuracy through a shared database and defined application-to-deployment pipeline. In conclusion, the project demonstrates how digital solutions can modernize practicum management by integrating internship deployment, attendance monitoring, document tracking, and coordinator reporting in one platform.",
        "Normal (Web)",
        align=WD_ALIGN_PARAGRAPH.JUSTIFY,
        bold=False,
        size=12,
    )

    page_break(doc)

    # ----- TOC -----
    h1(doc, "Table of Contents")
    toc_table(doc)

    # ----- BODY SECTION: arabic page 1 -----
    new_sec = doc.add_section(WD_SECTION.NEW_PAGE)
    set_margins(new_sec, left=1.5, right=1.0, top=1.0, bottom=1.0, header=0.5, footer=0.5)
    new_sec.different_first_page_header_footer = False
    set_title_page(new_sec, False)
    set_pg_num(new_sec, fmt="decimal", start=1)
    setup_footer(new_sec, include_label=True)

    # ----- INTRODUCTION -----
    h1(doc, "Introduction")
    h2(doc, "Project Context")
    body(
        doc,
        "PRACTICOM is an integrated web and mobile platform developed for STI College Ormoc to manage On-the-Job Training (OJT) across three academic programs: BS Information Technology (BSIT), BS Tourism Management (BSTM), and BS Hospitality Management (BSHM). The system addresses the inefficiencies of manual OJT coordination—spreadsheet-based tracking, paper endorsements, fragmented attendance records, and delayed communication among students, host companies, and school administrators.",
    )
    body(
        doc,
        "Internship programs require sustained coordination among multiple stakeholders. Students must discover opportunities, submit applications, and accumulate required training hours. Industry partners must review applicants, supervise interns, and validate attendance. OJT coordinators must enforce course-specific requirements, approve deployments, generate official documents such as endorsement letters and Memoranda of Agreement (MOA), and monitor student progress. Without a centralized system, these processes become error-prone, time-consuming, and difficult to audit.",
    )
    body(
        doc,
        "PRACTICOM centralizes the full OJT lifecycle into a single platform with four access points: a public landing page for internship discovery, an administrative web panel for coordinators and super administrators, an industry partner web portal for host companies, and a cross-platform Flutter mobile application for students and coordinators. All components connect to a shared PHP REST API backed by a MySQL database (practicom_db), deployed locally through XAMPP and in production at practicom.online.",
    )
    body(
        doc,
        "The platform implements a structured application-to-deployment pipeline. Students authenticate via Microsoft Office 365 OAuth, complete their profiles, browse course-matched internship postings, and submit applications with supporting documents. Coordinators manage applications through defined stages—pending, under review, for interview, for deployment, and deployed—while tracking partner approvals and MOA compliance. Upon deployment, students record attendance through mobile clock-in and clock-out, optionally verified through facial recognition via the Face++ API. Accumulated hours are measured against program requirements (486 hours for BSIT; 600 hours for BSTM and BSHM), with host companies reviewing attendance logs and coordinators monitoring progress through analytics dashboards.",
    )
    body(
        doc,
        "Beyond deployment tracking, PRACTICOM includes document management, in-app notifications, announcements, calendar events, student excuse submission, project-based completion criteria, and attendance reporting. Role-based access control distinguishes super administrators from course-scoped OJT coordinators, while industry partners manage postings and intern supervision independently through their portal.",
    )
    body(
        doc,
        "The technology stack combines PHP and MySQL for server-side logic, HTML, CSS, and JavaScript for web interfaces, and Flutter (Dart) for the mobile client. External integrations include Azure Active Directory for institutional login, Face++ for biometric attendance, Semaphore for SMS verification, and Maileroo for partner onboarding emails.",
    )
    body(
        doc,
        "PRACTICOM represents a capstone-level, institution-specific solution that automates multi-stakeholder OJT workflows, enforces academic compliance, and provides real-time monitoring of student internship progress. It offers a practical and replicable model for Philippine higher education institutions seeking to modernize internship management through integrated web and mobile technologies.",
    )

    h2(doc, "Purpose and Description")
    body(
        doc,
        "The purpose of this project is to develop PRACTICOM, a Digital Practicum and Internship Deployment and Monitoring System that provides a secure, user-friendly, and efficient platform for managing On-the-Job Training at STI College Ormoc. This system addresses several limitations of the previous process, including paper-based applications, email correspondence, spreadsheet hour tracking, and in-person document submission.",
    )
    body(
        doc,
        "The system is a full-stack platform composed of a PHP/MySQL web backend, three web-based client interfaces, and a Flutter mobile application, deployed locally via XAMPP and in production at practicom.online. The public landing page allows students to browse active internship postings and featured industry partners. The administrative web panel serves OJT coordinators and super administrators with modules for partner management, internship posting oversight, application review, deployment monitoring, document generation, student registry, and attendance analytics. The industry partner portal enables host companies to review applicants, manage listings, validate attendance records, and communicate with deployed interns.",
    )
    body(
        doc,
        "The mobile application serves as the primary interface for students. Students authenticate through institutional Microsoft Office 365 accounts via OAuth 2.0, complete their profiles, browse course-matched postings, and submit applications with supporting documents. Applications follow a defined pipeline—pending, under review, for interview, for deployment, and deployed—managed by coordinators who schedule interviews, generate endorsement letters, and track Memoranda of Agreement with partner companies. During active deployment, students log daily attendance through mobile clock-in and clock-out, optionally verified through facial recognition via the Face++ API to prevent fraudulent time logging.",
    )
    body(
        doc,
        "Beyond deployment tracking, PRACTICOM provides in-app notifications, announcements, calendar events, student excuse submission, and attendance reporting. Role-based access control distinguishes super administrators from course-scoped coordinators, ensuring each administrator manages only their assigned programs. External integrations—including Azure Active Directory, Semaphore for SMS verification, and Maileroo for transactional email—support secure authentication and partner onboarding. Through these capabilities, PRACTICOM serves as the official OJT portal for STI College Ormoc.",
    )

    add_p(doc, "Objectives", "Normal", align=WD_ALIGN_PARAGRAPH.LEFT, bold=True, size=12)
    add_p(doc, "General Objective:", "Normal", align=WD_ALIGN_PARAGRAPH.LEFT, bold=True, size=12)
    body(
        doc,
        "To design, develop, and deploy PRACTICOM, an integrated web and mobile platform that digitizes the full On-the-Job Training lifecycle at STI College Ormoc, connecting students, OJT coordinators, and industry partners through a shared database, REST API, and role-based interfaces.",
    )
    add_p(doc, "Specific Objectives:", "Body of Research", bold=True, size=12, align=WD_ALIGN_PARAGRAPH.LEFT)
    objective(
        doc,
        "To develop a centralized internship management platform that allows OJT coordinators and super administrators to manage industry partners, internship postings, student applications, deployments, and official documents through a unified web-based administrative panel.",
        "That pertains to replacing spreadsheet tracking and paper routing with one administrative console for practicum operations.",
    )
    objective(
        doc,
        "To implement a mobile application for students and coordinators that supports Office 365 authentication, internship browsing and application, profile and document management, real-time attendance logging, and coordinator-side monitoring of student progress.",
        "That pertains to giving students and field coordinators a portable interface for application status, duty hours, and monitoring.",
    )
    objective(
        doc,
        "To automate the application-to-deployment workflow with a structured multi-stage pipeline—from submission through interview scheduling to final deployment—while enforcing course-specific requirements for BSIT, BSTM, and BSHM programs.",
        "That pertains to moving each application through pending, under review, for interview, for deployment, and deployed with program hour rules of 486 hours for BSIT and 600 hours for BSTM and BSHM.",
    )
    objective(
        doc,
        "To integrate biometric attendance verification and hour tracking using facial recognition at clock-in and clock-out, enabling accurate recording of rendered hours, company review of attendance logs, and compliance with program hour requirements.",
        "That pertains to reducing fraudulent time logging and providing coordinators with auditable attendance records.",
    )
    objective(
        doc,
        "To provide an industry partner web portal for host companies to publish opportunities, review applicants, supervise deployed interns, and validate time logs.",
        "That pertains to giving verified companies a dedicated channel instead of informal email and messaging follow-ups.",
    )
    objective(
        doc,
        "To provide communication, document management, and analytics capabilities including in-app notifications, announcements, calendar events, MOA and endorsement letter tracking, excuse submission, and dashboard reporting to support data-driven OJT program management.",
        "That pertains to keeping official notices, documents, and progress summaries inside the same system used for deployment.",
    )

    h2(doc, "Scope and Limitations")
    body(
        doc,
        "This capstone project aims to enhance On-the-Job Training management at STI College Ormoc by developing a more secure, efficient, and user-friendly Digital Practicum and Internship Deployment and Monitoring System. The study is generally useful in strengthening record accuracy, improving coordination, and simplifying the overall management of practicum deployment. The main beneficiaries of the system include OJT coordinators and administrators of STI College Ormoc, students in BSIT, BSTM, and BSHM as interns, and industry partners as host companies.",
    )
    body(
        doc,
        "The project will run during the academic year 2025–2026, with development from January 2026 to final submission on May 15, 2026. The proposed software features include a public internship landing page; an administrative web panel; an industry partner portal; a Flutter mobile application; partner onboarding and approval; internship posting management; student application and multi-stage deployment workflow; attendance logging with optional face verification; document management for MOAs and endorsement letters; in-app notifications; announcements; calendar events; and coordinator analytics dashboards. The system also includes the modules:",
    )
    add_p(doc, "Administrative Web Panel:", "Body of Research", bold=True, size=12, align=WD_ALIGN_PARAGRAPH.LEFT)
    body(doc, "Dashboard: Displays application, deployment, attendance, and document summaries.")
    body(doc, "Partner Management: Includes onboarding, document verification, approval, and rejection of host companies.")
    body(doc, "Application Processing: Moves student applications through pending, under review, for interview, for deployment, and deployed.")
    body(doc, "Student Registry and Deployment Monitoring: Tracks assigned programs, rendered hours, and official documents.")
    add_p(doc, "Industry Partner Portal:", "Body of Research", bold=True, size=12, align=WD_ALIGN_PARAGRAPH.LEFT)
    body(doc, "Company authentication, required password change, verification document upload, posting management, applicant review, intern lists, and time-log validation.")
    add_p(doc, "Mobile Application:", "Body of Research", bold=True, size=12, align=WD_ALIGN_PARAGRAPH.LEFT)
    body(doc, "Student sign-in through Microsoft Office 365, profile completion, posting browse and apply, application status, attendance clock-in and clock-out, notifications, and excuse submission. Coordinator mobile access is provided for attendance monitoring and selected student reports.")
    add_p(doc, "Backend and Integrations:", "Body of Research", bold=True, size=12, align=WD_ALIGN_PARAGRAPH.LEFT)
    body(doc, "PHP REST API and MySQL database (practicom_db). Microsoft Office 365 / Azure Active Directory for student authentication, Face++ for optional facial recognition, Semaphore for SMS verification, and Maileroo for transactional email. Local hosting through XAMPP and production hosting at practicom.online.")

    add_p(doc, "Limitations", "Heading 2")
    body(doc, "The system is intended only for the On-the-Job Training program of STI College Ormoc covering BSIT, BSTM, and BSHM.")
    body(doc, "The platform does not include a dedicated mobile application for industry partners; host companies access the platform exclusively through the web portal.")
    body(doc, "Student authentication relies on Microsoft Office 365 accounts issued by STI College Ormoc. Users without valid institutional credentials cannot access the student mobile sign-in.")
    body(doc, "All core functions need a stable internet connection. Offline mode is not supported.")
    body(doc, "Biometric attendance verification depends on the Face++ API, and partner onboarding relies on Semaphore and Maileroo. Service outages or API changes with these providers may affect related functions.")
    body(doc, "Production-grade security hardening such as HTTPS enforcement, CSRF protection, and stronger password hashing for web accounts remains recommended for institutional rollout.")

    h2(doc, "Review of Related Literature/Studies/Systems")
    body(
        doc,
        "This chapter presents a review of studies and systems that support the development of a digital practicum and internship deployment and monitoring platform for STI College Ormoc. It discusses past work about internship management systems, stakeholder communication, attendance monitoring, and web–mobile architectures to serve as the foundation for PRACTICOM. The review is organized by themes to highlight key differences, common issues, and useful ideas for the project.",
    )
    add_p(doc, "Typology and Thematic Grouping of Related Literature", "Body of Research", bold=True, size=12, align=WD_ALIGN_PARAGRAPH.LEFT)
    body(
        doc,
        "The reviewed materials are organized into four main themes: (1) Internship Management and Stakeholder Communication, (2) Centralized Placement and Monitoring Platforms, (3) Attendance Monitoring and Biometric Verification, and (4) Web and Mobile Architectures in Academic Operations. This structure helps explain the strengths, weaknesses, and lessons from each group, which will guide the proposed system’s design.",
    )
    add_p(doc, "1. Internship Management and Stakeholder Communication", "Body of Research", bold=True, size=12, align=WD_ALIGN_PARAGRAPH.LEFT)
    body(
        doc,
        "Nevetha and Amutha (2022) developed an internship management system as a communication channel among students, educational institutions, and business organizations. This led to reduced errors in placement management and faster information delivery to students. The approach aligns well with PRACTICOM, which treats the student, the school, and the host company as the three required parties in OJT.",
    )
    body(
        doc,
        "Likewise, Mydyti and Kadriu (2020) proposed a web-based internship management system to improve the relationship among internship seekers, employers, and educational institutions. Though general in scope, their student–business–academia model and PHP/MySQL recommendation are suitable as a foundation for an institution-specific OJT system. PRACTICOM strikes a balance by adding course-specific hour rules, a multi-stage deployment pipeline, and a mobile client for daily attendance.",
    )
    add_p(doc, "2. Centralized Platforms for Placement and Monitoring", "Body of Research", bold=True, size=12, align=WD_ALIGN_PARAGRAPH.LEFT)
    body(
        doc,
        "Wan Abdul Rahman, Mohamad Bustamam, and Putra (2024) developed E-Kehadiran, a cloud-oriented web system for internship attendance and logbook handling. They emphasize accurate attendance, supervisor monitoring, and clearer collaboration among administrators, supervisors, and interns. PRACTICOM adopts the core principle of treating attendance and progress as first-class records stored in one database.",
    )
    add_p(doc, "3. Attendance Monitoring and Biometric Verification", "Body of Research", bold=True, size=12, align=WD_ALIGN_PARAGRAPH.LEFT)
    body(
        doc,
        "Studies on educational attendance systems demonstrate that biometric verification, such as facial recognition, enhances the reliability of time logging and reduces fraudulent attendance records. PRACTICOM records clock-in and clock-out on the mobile application and optionally verifies identity through the Face++ API. The literature also cautions that biometric services introduce privacy, connectivity, and vendor-dependency issues; these constraints are acknowledged in the limitations of this study.",
    )
    add_p(doc, "4. Web and Mobile Architectures in Academic Operations", "Body of Research", bold=True, size=12, align=WD_ALIGN_PARAGRAPH.LEFT)
    body(
        doc,
        "Research on educational technology supports the use of web dashboards for administrators and mobile applications for students who work off campus. REST APIs and JSON payloads keep multiple clients synchronized against one database. PRACTICOM applies this architecture: PHP handles business logic and API endpoints; MySQL stores partners, applications, deployments, attendance, and documents; Flutter serves students and mobile coordinators; and browser-based PHP pages serve administrators and companies.",
    )
    add_p(doc, "Summary of Local and Foreign Studies and Identified Gaps", "Body of Research", bold=True, size=12, align=WD_ALIGN_PARAGRAPH.LEFT)
    body(
        doc,
        "Across the reviewed literature, commonalities include the need for stakeholder communication, centralized records, and mobile accessibility. Challenges identified include manual hour tracking, weak document control, and systems that remain web-only or lack course-specific OJT rules. Commercial learning management and human resource systems offer partial solutions for student tracking and employee attendance but often lack features specific to OJT workflows, such as multi-stage application pipelines, MOA tracking, and coordinator–company–student collaboration.",
    )
    body(
        doc,
        "PRACTICOM addresses this gap by integrating internship deployment, attendance verification, document management, and analytics into a single platform tailored to the OJT requirements of STI College Ormoc. It does not attempt city-wide multi-school placement. Its contribution is a practical, localized OJT system that matches the actual coordination work of the institution.",
    )

    # ----- METHODOLOGY -----
    add_p(doc, "METHODOLOGY", "Body of Research", align=WD_ALIGN_PARAGRAPH.CENTER, bold=True, size=12)
    add_p(doc, "Technical Background", "Body of Research", align=WD_ALIGN_PARAGRAPH.LEFT, bold=True, size=12)
    body(
        doc,
        "The proposed system for PRACTICOM adopts a three-tier client–server architecture to provide a secure, accessible, and efficient practicum platform tailored for STI College Ormoc. In alignment with current technological trends, the system utilizes a combination of web technologies, a cross-platform mobile framework, and external authentication and messaging services to ensure functionality and reliability for students, coordinators, and industry partners.",
    )
    add_p(doc, "Technologies to be Used", "Body of Research", bold=True, size=12, align=WD_ALIGN_PARAGRAPH.LEFT)
    add_p(doc, "Web Technologies", "Body of Research", bold=True, size=12, align=WD_ALIGN_PARAGRAPH.LEFT)
    body(
        doc,
        "The frontend of the web components will be developed using HTML, CSS, and JavaScript, which are standard technologies for building responsive and interactive web applications. The backend will be powered by PHP, a server-side scripting language known for its compatibility with web servers and its ability to handle dynamic content and REST API endpoints. It allows secure interaction between the application and the database, particularly for functions like application processing, attendance posting, document handling, and admin control.",
    )
    add_p(doc, "Database Management", "Body of Research", bold=True, size=12, align=WD_ALIGN_PARAGRAPH.LEFT)
    body(
        doc,
        "The system will use MySQL as its relational database management system. MySQL is well-suited for handling structured data and supports essential functions like data indexing, record tracking, and transaction management. It will store key information such as users, partners, applications, deployments, attendance, documents, and system logs in a centralized database named practicom_db.",
    )
    add_p(doc, "Mobile Application", "Body of Research", bold=True, size=12, align=WD_ALIGN_PARAGRAPH.LEFT)
    body(
        doc,
        "The mobile application will be built using the Flutter framework and Dart programming language, enabling deployment on Android and iOS from a single codebase. Communication between the mobile client and the backend is handled through HTTP-based REST API calls using JSON-formatted request and response data. Mobile authentication uses bearer token authorization.",
    )
    add_p(doc, "Authentication and Security Tools", "Body of Research", bold=True, size=12, align=WD_ALIGN_PARAGRAPH.LEFT)
    body(
        doc,
        "Student authentication is implemented through Microsoft Office 365 OAuth 2.0 with PKCE, allowing users to sign in using their institutional STI accounts. Attendance verification integrates the Face++ API for facial recognition during clock-in and clock-out. Additional external services include Semaphore for SMS verification during partner registration and Maileroo for transactional email notifications. The system follows role-based access control, distinguishing students, OJT coordinators, super administrators, and industry partners.",
    )
    add_p(doc, "Platform Hosting and Environment", "Body of Research", bold=True, size=12, align=WD_ALIGN_PARAGRAPH.LEFT)
    body(
        doc,
        "During development, the system will be hosted locally using XAMPP, which bundles Apache, PHP, and MySQL for testing purposes. For production, it is deployed at practicom.online. Development tools include phpMyAdmin, Visual Studio Code or Cursor, Android Studio, Git, and standard web browsers for administrative and company testing.",
    )

    add_p(doc, "Calendar of Activities", "Body of Research", bold=True, size=12, align=WD_ALIGN_PARAGRAPH.LEFT)
    body(
        doc,
        "Project work ran from topic approval in January 2026 through final submission on May 15, 2026. Documentation was written in parallel with development.",
    )
    add_picture(doc, "image1.png", width=6.0)
    caption(doc, "Figure 1. Calendar of Activities (January 2026–May 15, 2026)")
    add_p(doc, "System Planning and Requirements", "Body of Research", bold=True, size=12, align=WD_ALIGN_PARAGRAPH.LEFT)
    body(doc, "Objective: To define the project’s scope, objectives, and feature set.")
    body(doc, "Resources: Laptop, internet access, documentation tools such as Microsoft Word.")
    add_p(doc, "System Design and Database Design", "Body of Research", bold=True, size=12, align=WD_ALIGN_PARAGRAPH.LEFT)
    body(doc, "Objective: To prepare process models, interface structure, and the MySQL schema.")
    body(doc, "Resources: Draw.io or equivalent, phpMyAdmin, and the proposed data dictionary.")
    add_p(doc, "Web Development", "Body of Research", bold=True, size=12, align=WD_ALIGN_PARAGRAPH.LEFT)
    body(doc, "Objective: To build the public landing page, administrative panel, and industry partner portal.")
    body(doc, "Resources: PHP, MySQL, HTML, CSS, JavaScript, and Apache via XAMPP.")
    add_p(doc, "Mobile Application Development", "Body of Research", bold=True, size=12, align=WD_ALIGN_PARAGRAPH.LEFT)
    body(doc, "Objective: To implement student and coordinator mobile features including application and attendance.")
    body(doc, "Resources: Flutter, Dart, Android Studio, and test smartphones.")
    add_p(doc, "Testing, Finalization, Documentation, and Defense", "Body of Research", bold=True, size=12, align=WD_ALIGN_PARAGRAPH.LEFT)
    body(doc, "Objective: To merge modules, resolve defects, complete the manuscript, and prepare oral defense.")
    body(doc, "Resources: Test accounts, production host, Microsoft Word, and PowerPoint.")

    h2(doc, "Resources")
    body(
        doc,
        "This section outlines the specific hardware and software resources required by the proponents to develop, test, and complete PRACTICOM. These resources are essential for system design, development, integration, and deployment.",
    )
    h2(doc, "Human Resources")
    h2(doc, "Web Developer")
    h2(doc, "Database")
    h2(doc, "Hardware Resources")
    h2(
        doc,
        "Laptops — The project will require five laptops, one for each researcher, equipped with at least an Intel Core i5 (8th Generation) processor, 8 GB of RAM, and 256 GB SSD storage. These will be utilized for system development, testing, and documentation.",
    )
    h2(
        doc,
        "Smartphones — Two to three Android smartphones running Android 10 or higher, with a minimum of 3 GB RAM and a camera, will be used to test the Flutter application, attendance logging, and facial verification.",
    )
    h2(
        doc,
        "Broadband Internet Connection — A stable internet connection is necessary for API testing, Microsoft Office 365 login testing, SMS and email services, team collaboration, and access to the production host.",
    )
    h2(
        doc,
        "External Storage Devices — USB flash drives or portable hard drives will be used for system backup, data portability, and storing offline versions of documents and source code.",
    )
    h2(
        doc,
        "Printer — A printer will be used for hard copies of documents such as the manuscript, endorsement samples, and defense presentation materials.",
    )
    h2(doc, "Software Resources")
    h2(
        doc,
        "XAMPP — This software will serve as the local server environment, allowing the proponents to test and run PHP and MySQL scripts on their machines. MySQL and phpMyAdmin — These tools will be used to design and manage the relational database structure that stores information such as users, partners, applications, attendance, and logs.",
    )
    h2(
        doc,
        "Visual Studio Code — This will be the primary code editor for developing the system’s frontend and backend functionalities.",
    )
    h2(
        doc,
        "Google Chrome — These browsers will be used to test the system’s interface, functionality, and responsiveness across different environments.",
    )
    h2(
        doc,
        "HTML, CSS, JavaScript, PHP — These core web development languages will be used for building the administrative panel, company portal, and public pages.",
    )
    h2(
        doc,
        "Flutter and Dart — These will be used to build the cross-platform mobile application for students and coordinators.",
    )
    h2(
        doc,
        "Microsoft Office 365 / Azure AD — This will handle student login through institutional accounts.",
    )
    h2(
        doc,
        "Face++ API — This service will handle optional facial recognition during attendance logging.",
    )
    h2(
        doc,
        "Semaphore and Maileroo — These services will handle SMS verification and transactional email for partner onboarding.",
    )
    h2(
        doc,
        "Microsoft Word — These tools will be used for drafting system documentation, reports, and defense requirements.",
    )
    h2(
        doc,
        "Microsoft PowerPoint & Canva — These applications will support the creation of engaging presentation materials for the final defense.",
    )
    h2(
        doc,
        "Microsoft Excel — These will be used for tracking progress, generating Gantt charts, and organizing reports or test data.",
    )

    h2(doc, "Requirements Analysis")
    body(
        doc,
        "PRACTICOM is designed to offer a reliable, secure, and accessible computing solution for facilitating practicum and internship deployment at STI College Ormoc. This section presents a detailed analysis of the current needs and operational gaps using the WH questions (Who, What, Where, When, How) framework.",
    )
    add_p(doc, "Who – The People Involved", "Body of Research", bold=True, size=12, align=WD_ALIGN_PARAGRAPH.LEFT)
    body(doc, "OJT Coordinators and Administrators (STI College Ormoc) – Responsible for managing partners, applications, deployments, documents, and reports.")
    body(doc, "Students (BSIT, BSTM, BSHM) – Enrolled students who apply for internships, render hours, and complete practicum requirements.")
    body(doc, "Industry Partners – Host companies that publish opportunities, review applicants, and supervise deployed interns after verification.")
    body(doc, "Proponents/Developers – The research team composed of Aragon, Gajol, Mojado, Romo, and Socorin, who are responsible for designing, building, and deploying the system.")
    add_p(doc, "What – The Business Activity", "Body of Research", bold=True, size=12, align=WD_ALIGN_PARAGRAPH.LEFT)
    body(
        doc,
        "The core business activity is practicum and internship deployment for STI College Ormoc. This involves collecting and validating applications, displaying placement and hour statistics, managing partners under each program, and producing accurate records for academic compliance. The current activity suffers from issues in verification, manual data handling, delayed reporting, and limited visibility. The proposed system aims to automate and simplify these operations.",
    )
    add_p(doc, "Where – The Environment", "Body of Research", bold=True, size=12, align=WD_ALIGN_PARAGRAPH.LEFT)
    body(
        doc,
        "At present, OJT coordination operates through campus offices, paper files, spreadsheets, email, and messaging apps. While this allows deployment to proceed, it lacks a centralized system that ensures status tracking, attendance verification, and real-time monitoring. The proposed system will enhance this environment by introducing a web and mobile platform designed for structured, secure, and role-based participation. Coordinators use the administrative website; students and coordinators use the mobile application; companies use the web portal after approval.",
    )
    add_p(doc, "When – The Timing", "Body of Research", bold=True, size=12, align=WD_ALIGN_PARAGRAPH.LEFT)
    body(
        doc,
        "The official OJT cycle is conducted by program during the deployment semester, including application periods and daily attendance while students are deployed. Dashboards remain available for monitoring whenever the server is online. The development calendar ran from January 2026 to May 15, 2026.",
    )
    add_p(doc, "How – Current Procedures vs. Proposed Solution", "Body of Research", bold=True, size=12, align=WD_ALIGN_PARAGRAPH.LEFT)
    body(
        doc,
        "The current OJT process is conducted through forms, email, spreadsheets, and face-to-face follow-ups. It does not prevent incomplete files, and it offers limited administrative control or real-time data visibility. To address these limitations, the proposed system introduces a more structured platform. Specifically: student identity for the mobile application will be verified through Office 365; applications will move through a defined status pipeline; attendance will be logged on mobile with optional facial recognition; administrators will gain access to a secure dashboard; and companies will use a dedicated portal after verification. This improved approach ensures a more reliable, transparent, and user-friendly experience for students, coordinators, and host companies.",
    )

    h2(doc, "Requirements Documentation")
    body(
        doc,
        "This section records the agreement of intent between the client context (STI College Ormoc OJT practice) and the developers on what the software should do. The system is accepted when these capabilities are demonstrable.",
    )
    add_p(doc, "Login Page", "Body of Research", bold=True, size=12, align=WD_ALIGN_PARAGRAPH.LEFT)
    body(doc, "Student sign-in via Office 365; coordinator and admin sign-in on web and mobile; company sign-in on web; role-based access to modules.")
    add_p(doc, "Dashboard", "Body of Research", bold=True, size=12, align=WD_ALIGN_PARAGRAPH.LEFT)
    body(doc, "Coordinator and administrator dashboards and reports for applications, deployments, students, hours, documents/MOA, and attendance.")
    add_p(doc, "Student Mobile", "Body of Research", bold=True, size=12, align=WD_ALIGN_PARAGRAPH.LEFT)
    body(doc, "Browse internship posts, view details, apply with résumé and contact data; view application status; attendance (clock in/out, logs, summaries); notifications; profile updates as provided by APIs.")
    add_p(doc, "Partner Management", "Body of Research", bold=True, size=12, align=WD_ALIGN_PARAGRAPH.LEFT)
    body(doc, "Manage companies including approval and rejection, posting oversight, and verification documents.")
    add_p(doc, "Application Processing", "Body of Research", bold=True, size=12, align=WD_ALIGN_PARAGRAPH.LEFT)
    body(doc, "Manage posts and applications through defined status workflows from pending through deployed.")
    add_p(doc, "Company Portal", "Body of Research", bold=True, size=12, align=WD_ALIGN_PARAGRAPH.LEFT)
    body(doc, "After password change and document verification, access company portal features (applicants, interns, postings, time logs, announcements/calendar as implemented).")
    add_p(doc, "Attendance and Hours", "Body of Research", bold=True, size=12, align=WD_ALIGN_PARAGRAPH.LEFT)
    body(doc, "Clock-in and clock-out, optional facial recognition, company review of logs, and comparison of accumulated hours with program requirements.")
    add_p(doc, "Documents and Notifications", "Body of Research", bold=True, size=12, align=WD_ALIGN_PARAGRAPH.LEFT)
    body(doc, "MOA and endorsement letter tracking, file uploads, in-app notifications, announcements, calendar events, and excuse submission.")
    add_p(doc, "Security and Data Integrity", "Body of Research", bold=True, size=12, align=WD_ALIGN_PARAGRAPH.LEFT)
    body(doc, "Passwords and tokens handled per design; file uploads validated; inactive or wrong-role users blocked.")

    # ----- DESIGN MODEL -----
    h1(doc, "Design Model")
    add_p(doc, "Traditional/Existing Design Model", "Body of Research", bold=True, size=12, align=WD_ALIGN_PARAGRAPH.LEFT)
    add_picture(doc, "image2.jpeg", width=6.2)
    caption(doc, "Figure 2. Traditional OJT process flow")
    add_p(doc, "Proposed Design Model", "Body of Research", bold=True, size=12, align=WD_ALIGN_PARAGRAPH.LEFT)
    add_picture(doc, "image5.jpeg", width=5.2)
    caption(doc, "Figure 3. Role-based system access flow")

    h2(doc, "Design of Software, System, Product, and/or Processes")
    body(
        doc,
        "The research proponents will design the proposed system using a structured approach that consists of four phases: planning, analysis, design, and implementation. The planning phase will focus on identifying the scope, objectives, and beneficiaries of the system, ensuring that it meets the needs of students, coordinators, and industry partners. The analysis phase will involve gathering requirements, identifying existing problems in the OJT process, and modeling solutions through tools such as process flowcharts and the role-based access flow. The design phase will translate these requirements into logical and physical designs, including the database schema, user interface layouts, and system architecture aligned with usability and security standards. Finally, the implementation phase will cover coding, integration, testing, and deployment of the system using PHP, MySQL, and Flutter, followed by validation through representative OJT workflows of STI College Ormoc.",
    )
    h2(doc, "Development")
    body(
        doc,
        "The development of the proposed system was carried out in accordance with the structured approach consisting of planning, analysis, design, and implementation. In the planning phase, the developers identified the appropriate technologies, frameworks, and standards to be used, ensuring that the system would be secure, scalable, and user-friendly. The analysis phase involved breaking down functional requirements into smaller development tasks such as partner onboarding, postings, applications, deployment, attendance, documents, and notifications. The design phase focused on translating requirements into code structure, database schema, and interface layouts, following coding standards and best practices for maintainability. Finally, in the implementation phase, the system was developed using PHP, JavaScript, HTML, CSS, MySQL, and Flutter, integrating features such as Office 365 authentication, attendance logging, and the application-to-deployment workflow, with continuous testing and debugging to ensure functionality, usability, and compliance with the requirements of STI College Ormoc’s OJT program.",
    )

    # ----- RESULTS -----
    h1(doc, "RESULTS AND DISCUSSION")
    h2(doc, "Testing")
    body(
        doc,
        "The proponents tested the system in accordance with software development standards to ensure functionality, security, and usability. Each module was tested individually through unit testing, followed by integration testing to verify that the modules worked together correctly. The system was also subjected to user acceptance testing using representative student, coordinator, and company accounts to validate that the features met the actual requirements. Security and data integrity were checked through role-denial tests, while the interface was tested for responsiveness on both desktop browsers and Android devices.",
    )
    h2(doc, "Description of Prototype")
    body(
        doc,
        "The prototype was designed as a web and mobile practicum system that can be accessed through standard browsers and the Flutter application. The system requirements include a PHP-compatible server, MySQL database, and stable internet connectivity. The preliminary design follows a modular structure, consisting of the public landing page, administrative panel, industry partner portal, student mobile application, attendance, documents, and notifications. Evaluation and testing were performed by running trial partner registrations, submitting sample applications, posting attendance, and generating coordinator summaries to confirm that the system flows correctly. Feedback from testers was gathered to refine the user interface, improve workflows, and fix any issues identified during the evaluation process.",
    )
    h2(doc, "Implementation Plan")
    body(
        doc,
        "The Implementation Plan describes how the information system will be deployed, installed, and transitioned into an operational system. The plan contains an overview of the system, a brief description of the major tasks involved in the implementation, the overall resources needed to support the implementation effort (such as hardware, software, facilities, materials, and personnel), and any site-specific implementation requirements. The major tasks include:",
    )
    body(doc, "Installation of the system in the designated hosting environment.")
    body(doc, "Configuration of Office 365 authentication, programs, coordinator assignments, and initial partner records.")
    body(doc, "Testing and validation of application-to-deployment, attendance, and document tracking before live use.")
    body(doc, "Training of OJT coordinators and partner representatives in using the administrative tools and company portal.")
    body(doc, "Deployment of the system for the STI College Ormoc OJT cycle, with live monitoring of applications, deployments, and hours.")
    add_p(doc, "Implementation Results", "Heading 2")
    body(
        doc,
        "The implementation of the prototype produced a functional system capable of handling partner onboarding, internship postings, student applications, deployment, attendance logging, document tracking, and coordinator reporting. Initial outputs include application status records, attendance logs, hour totals against program requirements, and dashboard counts. These outputs served as the foundation for refining the project by improving interface design, adjusting workflows for coordinators, and enhancing the reliability of attendance posting. Feedback from testers guided revisions that made the system more user-friendly, responsive, and aligned with the needs of STI College Ormoc. The production URL practicom.online serves as the live host for demonstration.",
    )

    # ----- CONCLUSION -----
    add_p(doc, "CONCLUSION", "Normal", bold=True, size=12)
    body(
        doc,
        "The study focused on enhancing On-the-Job Training at STI College Ormoc through the development of PRACTICOM, a Digital Practicum and Internship Deployment and Monitoring System. The system was designed and implemented to address the limitations of the previous manual process, which relied on paper endorsements, spreadsheets, and informal follow-up among students, coordinators, and host companies. The platform now integrates modules for applications, deployment, attendance, documents, notifications, partner management, and reporting, providing a more secure and organized workflow for all stakeholders. Testing confirmed that the system successfully implemented its intended features such as Office 365 authentication, the multi-stage application pipeline, optional facial recognition at attendance, MOA and endorsement tracking, and coordinator dashboards. The results demonstrated improved usability, stronger data management, and better visibility of student progress.",
    )
    body(
        doc,
        "The findings lead to several conclusions. The system has significantly improved practicum coordination by allowing students to apply and track status through the mobile application, reducing delays caused by paper routing. Attendance logging and hour comparison against BSIT, BSTM, and BSHM requirements made progress easier to audit. Administrators also benefited from enhanced tools such as partner approval, application status management, and reporting, which provided greater transparency and efficiency in managing OJT.",
    )
    body(
        doc,
        "Based on these conclusions, several recommendations are proposed. It is suggested that future enhancements focus on production security hardening, including HTTPS enforcement and stronger password hashing for web accounts. The system may also benefit from a mobile companion view for industry partners and limited offline queuing for attendance in sites with unreliable connectivity. Finally, establishing a continuous monitoring and feedback mechanism with OJT coordinators and participating companies is recommended to ensure that the system remains adaptable to the evolving needs of the practicum program.",
    )

    # ----- REFERENCES -----
    h1(doc, "References")
    refs = [
        "Mydyti, H., & Kadriu, A. (2020). Using internship management system to improve the relationship between internship seekers, employers and educational institutions. ENTRENOVA - ENTerprise REsearch InNOVAtion, 6(1), 97–104. https://ojs.srce.hr/entrenova/article/view/13437",
        "Nevetha, P., & Amutha, N. (2022). Internship management system for communication between students and educational institutions. International Journal of Progressive Research in Science and Engineering, 3(03), 65–66. https://journal.ijprse.com/index.php/ijprse/article/view/518",
        "Wan Abdul Rahman, W. F., Mohamad Bustamam, M. S., & Putra, Y. H. (2024). The development of an integrated cloud-based system to enhance internship management. Journal of ICT in Education, 11(2), 92–110. https://doi.org/10.37134/jictie.vol11.2.8.2024",
    ]
    for ref in refs:
        p = add_p(doc, ref, "References", align=WD_ALIGN_PARAGRAPH.JUSTIFY, bold=False, size=12)
        p.paragraph_format.left_indent = Cm(1.27)
        p.paragraph_format.first_line_indent = Cm(-1.27)

    # ----- APPENDICES -----
    add_p(doc, "APPENDICES", "Normal", bold=True, size=12)
    add_p(doc, "APPENDIX A. RESOURCE PERSONS", "Appendix")
    t = doc.add_table(rows=5, cols=3)
    t.style = "Table Grid"
    hdr = ["Name", "Position / Office", "Contribution"]
    for i, h in enumerate(hdr):
        set_cell(t.rows[0].cells[i], h, bold=True, center=True, size=11)
    data = [
        ["Elizabeth L. Dumaran", "Capstone Project Adviser, STI College Ormoc", "Academic guidance and manuscript review"],
        ["Rona Mira B. Lucañas", "Program Head and Capstone Project Coordinator", "Program alignment and defense coordination"],
        ["OJT Coordinators (BSIT, BSTM, BSHM)", "STI College Ormoc", "Validation of current OJT procedures and hour rules"],
        ["Industry partner representatives", "Host companies", "Clarification of application, supervision, and attendance practice"],
    ]
    for i, row in enumerate(data, start=1):
        for j, val in enumerate(row):
            set_cell(t.rows[i].cells[j], val, bold=False, center=False, size=11)

    add_p(doc, "APPENDIX B. RELEVANT SOURCE CODE", "Normal", bold=True, size=12)
    body(
        doc,
        "The complete source code of PRACTICOM is maintained in the project repository and will be presented during oral defense. Representative areas of the codebase include PHP REST endpoints for authentication, applications, attendance, and partner management; MySQL migration scripts for practicom_db; administrative and company web modules; and the Flutter mobile client for student and coordinator functions.",
    )

    add_p(doc, "APPENDIX C. PERSONAL TECHNICAL VITAE", "Normal", bold=True, size=12)

    add_cv(
        doc,
        "ANGELO CHRISTIAN G. ARAGON",
        "Brgy. Don Felipe Larrazabal, Ormoc City, Leyte",
        "kairyuuken@gmail.com",
        "0953 117 3722",
        [
            ["Tertiary", "2023–present", "STI College Ormoc"],
            ["Tertiary", "2021–2023", "STI College Maasin"],
            ["Vocational/Technical", "2019–2021", "Sta. Paz National High School"],
            ["High School", "2017–2019", "Sta. Paz National High School"],
            ["High School", "2015–2017", "Santolan High School"],
            ["Elementary", "2010–2015", "Santolan Elementary School"],
        ],
        [["—", "—", "—"]],
        [
            ["C#", "Intermediate", "2022"],
            ["Java", "Intermediate", "2021"],
            ["HTML", "Intermediate", "2023"],
            ["JavaScript", "Intermediate", "2023"],
            ["SQL", "Intermediate", "2022"],
        ],
    )
    add_cv(
        doc,
        "JOHN LLOYD B. GAJOL",
        "Brgy. Alegria, Ormoc City, Leyte",
        "johnlloydgajol@gmail.com",
        "+63 936 411 3985",
        [
            ["Tertiary", "2021–present", "STI College Ormoc"],
            ["Vocational/Technical", "2019–2021", "STI College Ormoc"],
            ["High School", "2015–2019", "St. Peter’s College of Ormoc"],
            ["Elementary", "2009–2015", "Lorenzo Y. Palo Elementary School"],
        ],
        [["2024–present", "IT Club", "Member"]],
        [
            ["PHP, C#, HTML, CSS", "Basic", "January 2025"],
            ["MySQL, SQLite", "Basic", "January 2022"],
        ],
    )
    add_cv(
        doc,
        "JUDE Q. MOJADO",
        "Brgy. Linao, Ormoc City, Leyte",
        "mojado.371520@ormoc.sti.edu.ph",
        "0907 301 7987",
        [
            ["Tertiary", "2022–present", "STI College Ormoc"],
            ["Vocational/Technical", "2020–2022", "STI College Ormoc"],
            ["High School", "2015–2020", "Linao National High School"],
            ["Elementary", "2009–2015", "Linao Central School"],
        ],
        [["2024–present", "IT Club", "Member"]],
        [
            ["PHP, C#, HTML, CSS", "Basic", "January 2025"],
            ["MySQL, SQLite", "Basic", "January 2022"],
        ],
    )
    add_cv(
        doc,
        "JOSE ANDREW MIGUEL P. ROMO",
        "Brgy. Ipil, Ormoc City, Leyte",
        "jamromo121@gmail.com",
        "+63 921 738 6499",
        [
            ["Tertiary", "2021–present", "STI College Ormoc"],
            ["Vocational/Technical", "2019–2021", "STI College Ormoc"],
            ["High School", "2015–2019", "St. Peter’s College of Ormoc"],
            ["Elementary", "2009–2015", "Lorenzo Y. Palo Elementary School"],
        ],
        [["2024–present", "IT Club", "Member"]],
        [
            ["PHP, C#, HTML, CSS", "Basic", "January 2025"],
            ["MySQL, SQLite", "Basic", "January 2022"],
        ],
    )
    add_cv(
        doc,
        "STANLEY ARGY M. SOCORIN",
        "Brgy. Ipil, Ormoc City, Leyte",
        "[email to be confirmed]",
        "[mobile number to be confirmed]",
        [
            ["Tertiary", "2019–present", "STI College Ormoc"],
            ["Vocational/Technical", "2017–2019", "STI College Ormoc"],
            ["High School", "2012–2017", "St. Dominic Savio International School"],
            ["Elementary", "2006–2012", "Kinderland, Inc."],
        ],
        [["2024–present", "IT Club", "Member"]],
        [
            ["PHP, C#, HTML, CSS", "Basic", "January 2025"],
            ["MySQL, SQLite", "Basic", "January 2022"],
        ],
    )

    doc.save(str(OUT))
    print("Wrote", OUT)
    print("Size", OUT.stat().st_size)
    print("Styles ok Heading 1" , "Heading 1" in [s.name for s in doc.styles])
    print("Body of Research" in [s.name for s in doc.styles])


if __name__ == "__main__":
    build()
