"""
TOCCA Admin Portal - User Manual PDF Generator
Generates a professional, branded PDF manual using ReportLab.
"""

import os
from reportlab.lib.pagesizes import A4
from reportlab.lib.units import mm, cm
from reportlab.lib.colors import HexColor, white, black
from reportlab.lib.styles import getSampleStyleSheet, ParagraphStyle
from reportlab.lib.enums import TA_LEFT, TA_CENTER, TA_JUSTIFY
from reportlab.platypus import (
    SimpleDocTemplate, Paragraph, Spacer, Table, TableStyle,
    PageBreak, KeepTogether, HRFlowable,
)
from reportlab.platypus.flowables import Flowable
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont

# ── Brand colors ──────────────────────────────────────────────
BLUE_DARK   = HexColor("#0d47a1")
BLUE_MED    = HexColor("#1565c0")
BLUE_LIGHT  = HexColor("#e3f2fd")
BLUE_ACCENT = HexColor("#bbdefb")
YELLOW_LIGHT = HexColor("#fff8e1")
YELLOW_BORDER = HexColor("#f9a825")
GRAY_LIGHT  = HexColor("#f5f5f5")
GRAY_MED    = HexColor("#e0e0e0")
GRAY_TEXT   = HexColor("#424242")
GRAY_DARK   = HexColor("#212121")
WHITE       = white

PAGE_W, PAGE_H = A4
LEFT_MARGIN = 20 * mm
RIGHT_MARGIN = 20 * mm
TOP_MARGIN = 20 * mm
BOTTOM_MARGIN = 25 * mm
CONTENT_W = PAGE_W - LEFT_MARGIN - RIGHT_MARGIN

OUTPUT_PATH = os.path.join(os.path.dirname(os.path.abspath(__file__)),
                           "TOCCA_Admin_User_Manual.pdf")


# ── Custom flowables ─────────────────────────────────────────
class SectionHeading(Flowable):
    """Blue banner heading for major sections."""
    def __init__(self, text, number=None):
        super().__init__()
        self.text = text
        self.number = number
        self.width = CONTENT_W
        self.height = 12 * mm

    def draw(self):
        c = self.canv
        c.setFillColor(BLUE_DARK)
        c.roundRect(0, 0, self.width, self.height, 3, fill=1, stroke=0)
        c.setFillColor(WHITE)
        c.setFont("Helvetica-Bold", 13)
        label = f"{self.number}.  {self.text}" if self.number else self.text
        c.drawString(5 * mm, 3.5 * mm, label)


class SubHeading(Flowable):
    """Lighter blue sub-heading bar."""
    def __init__(self, text):
        super().__init__()
        self.text = text
        self.width = CONTENT_W
        self.height = 9 * mm

    def draw(self):
        c = self.canv
        c.setFillColor(BLUE_LIGHT)
        c.roundRect(0, 0, self.width, self.height, 2, fill=1, stroke=0)
        c.setStrokeColor(BLUE_DARK)
        c.setLineWidth(0.5)
        c.line(0, 0, self.width, 0)
        c.setFillColor(BLUE_DARK)
        c.setFont("Helvetica-Bold", 11)
        c.drawString(4 * mm, 2.5 * mm, self.text)


class CalloutBox(Flowable):
    """Colored callout box (info or warning style)."""
    def __init__(self, text, style="info", width=None):
        super().__init__()
        self.raw_text = text
        self.box_width = width or CONTENT_W
        if style == "warning":
            self.bg = YELLOW_LIGHT
            self.border = YELLOW_BORDER
            self.icon = "⚠"
        else:
            self.bg = BLUE_LIGHT
            self.border = BLUE_MED
            self.icon = "ℹ"
        self._para_style = ParagraphStyle(
            "callout_inner",
            fontName="Helvetica",
            fontSize=9,
            leading=13,
            textColor=GRAY_DARK,
        )
        self._para = Paragraph(f"<b>{self.icon}  </b>{text}", self._para_style)
        pw = self.box_width - 10 * mm
        _, self._ph = self._para.wrap(pw, 500 * mm)
        self.height = self._ph + 8 * mm
        self.width = self.box_width

    def draw(self):
        c = self.canv
        c.setFillColor(self.bg)
        c.setStrokeColor(self.border)
        c.setLineWidth(1.2)
        c.roundRect(0, 0, self.width, self.height, 3, fill=1, stroke=1)
        self._para.drawOn(c, 5 * mm, 4 * mm)


class StepBox(Flowable):
    """Numbered step indicator (blue circle with number)."""
    def __init__(self, number, text, width=None):
        super().__init__()
        self.number = str(number)
        self.box_width = width or CONTENT_W
        self._style = ParagraphStyle(
            "step_inner",
            fontName="Helvetica",
            fontSize=10,
            leading=14,
            textColor=GRAY_DARK,
        )
        self._para = Paragraph(text, self._style)
        pw = self.box_width - 18 * mm
        _, self._ph = self._para.wrap(pw, 500 * mm)
        self.height = max(self._ph + 4 * mm, 10 * mm)
        self.width = self.box_width

    def draw(self):
        c = self.canv
        # light bg
        c.setFillColor(HexColor("#f0f4fa"))
        c.roundRect(0, 0, self.width, self.height, 3, fill=1, stroke=0)
        # circle
        cx, cy = 6 * mm, self.height - 5.5 * mm
        c.setFillColor(BLUE_DARK)
        c.circle(cx, cy, 4 * mm, fill=1, stroke=0)
        c.setFillColor(WHITE)
        c.setFont("Helvetica-Bold", 10)
        c.drawCentredString(cx, cy - 1.2 * mm, self.number)
        # text
        self._para.drawOn(c, 14 * mm, 2 * mm)


# ── Helper builders ──────────────────────────────────────────
def styled_table(data, col_widths=None, header=True):
    """Build a table with TOCCA styling."""
    if col_widths is None:
        n = len(data[0])
        col_widths = [CONTENT_W / n] * n

    cell_style = ParagraphStyle("cell", fontName="Helvetica", fontSize=9,
                                leading=12, textColor=GRAY_DARK)
    header_style = ParagraphStyle("hdr", fontName="Helvetica-Bold", fontSize=9,
                                  leading=12, textColor=WHITE)

    wrapped = []
    for ri, row in enumerate(data):
        s = header_style if (ri == 0 and header) else cell_style
        wrapped.append([Paragraph(str(c), s) for c in row])

    t = Table(wrapped, colWidths=col_widths, repeatRows=1 if header else 0)
    cmds = [
        ("VALIGN",     (0, 0), (-1, -1), "TOP"),
        ("LEFTPADDING",  (0, 0), (-1, -1), 6),
        ("RIGHTPADDING", (0, 0), (-1, -1), 6),
        ("TOPPADDING",   (0, 0), (-1, -1), 5),
        ("BOTTOMPADDING",(0, 0), (-1, -1), 5),
        ("GRID",       (0, 0), (-1, -1), 0.4, GRAY_MED),
    ]
    if header:
        cmds.append(("BACKGROUND", (0, 0), (-1, 0), BLUE_DARK))
        cmds.append(("TEXTCOLOR",  (0, 0), (-1, 0), WHITE))
    for ri in range(1 if header else 0, len(data)):
        bg = WHITE if ri % 2 == (1 if header else 0) else GRAY_LIGHT
        cmds.append(("BACKGROUND", (0, ri), (-1, ri), bg))
    t.setStyle(TableStyle(cmds))
    return t


def para(text, style_name="body"):
    return Paragraph(text, styles[style_name])


def spacer(h=4):
    return Spacer(1, h * mm)


# ── Styles ────────────────────────────────────────────────────
styles = getSampleStyleSheet()

styles.add(ParagraphStyle(
    "body", parent=styles["Normal"],
    fontName="Helvetica", fontSize=10, leading=15,
    textColor=GRAY_DARK, alignment=TA_JUSTIFY,
    spaceAfter=4 * mm,
))

styles.add(ParagraphStyle(
    "body_bold", parent=styles["Normal"],
    fontName="Helvetica-Bold", fontSize=10, leading=15,
    textColor=GRAY_DARK, spaceAfter=2 * mm,
))

styles.add(ParagraphStyle(
    "toc_entry", parent=styles["Normal"],
    fontName="Helvetica", fontSize=11, leading=20,
    textColor=GRAY_DARK, leftIndent=10 * mm,
))

styles.add(ParagraphStyle(
    "toc_title", parent=styles["Normal"],
    fontName="Helvetica-Bold", fontSize=22, leading=28,
    textColor=BLUE_DARK, alignment=TA_CENTER,
    spaceAfter=10 * mm,
))

styles.add(ParagraphStyle(
    "phase_title", parent=styles["Normal"],
    fontName="Helvetica-Bold", fontSize=11, leading=15,
    textColor=BLUE_DARK, spaceBefore=2 * mm, spaceAfter=1 * mm,
))

styles.add(ParagraphStyle(
    "bullet", parent=styles["Normal"],
    fontName="Helvetica", fontSize=10, leading=14,
    textColor=GRAY_DARK, leftIndent=12 * mm, bulletIndent=6 * mm,
    spaceAfter=1.5 * mm,
))


# ── Page template callbacks ──────────────────────────────────
def _header_footer(canvas, doc):
    canvas.saveState()
    # footer line
    canvas.setStrokeColor(GRAY_MED)
    canvas.setLineWidth(0.5)
    y = BOTTOM_MARGIN - 8 * mm
    canvas.line(LEFT_MARGIN, y, PAGE_W - RIGHT_MARGIN, y)
    # footer text
    canvas.setFont("Helvetica", 7.5)
    canvas.setFillColor(GRAY_TEXT)
    canvas.drawString(LEFT_MARGIN, y - 4 * mm,
                      "TOCCA Admin Portal — Administrator User Manual  |  Version 2026.09")
    canvas.drawRightString(PAGE_W - RIGHT_MARGIN, y - 4 * mm,
                           f"Page {doc.page}")
    canvas.restoreState()


def _title_page_template(canvas, doc):
    """No header/footer on title page."""
    pass


# ── Build document ────────────────────────────────────────────
def build():
    doc = SimpleDocTemplate(
        OUTPUT_PATH,
        pagesize=A4,
        leftMargin=LEFT_MARGIN,
        rightMargin=RIGHT_MARGIN,
        topMargin=TOP_MARGIN,
        bottomMargin=BOTTOM_MARGIN,
        title="TOCCA Admin Portal — Administrator User Manual",
        author="City Government of Ormoc",
    )

    story = []

    # ═══════════════  TITLE PAGE  ═══════════════
    story.append(Spacer(1, 45 * mm))

    # Decorative top bar
    class TitleBar(Flowable):
        def __init__(self):
            super().__init__()
            self.width = CONTENT_W
            self.height = 4 * mm
        def draw(self):
            self.canv.setFillColor(BLUE_DARK)
            self.canv.rect(0, 0, self.width, self.height, fill=1, stroke=0)

    story.append(TitleBar())
    story.append(Spacer(1, 12 * mm))

    title_style = ParagraphStyle("title_main", fontName="Helvetica-Bold",
                                  fontSize=32, leading=38, textColor=BLUE_DARK,
                                  alignment=TA_CENTER)
    story.append(Paragraph("TOCCA Admin Portal", title_style))
    story.append(Spacer(1, 6 * mm))

    sub_style = ParagraphStyle("title_sub", fontName="Helvetica", fontSize=18,
                                leading=24, textColor=GRAY_DARK, alignment=TA_CENTER)
    story.append(Paragraph("Administrator User Manual", sub_style))
    story.append(Spacer(1, 20 * mm))

    story.append(TitleBar())
    story.append(Spacer(1, 12 * mm))

    info_style = ParagraphStyle("title_info", fontName="Helvetica", fontSize=13,
                                 leading=20, textColor=GRAY_TEXT, alignment=TA_CENTER)
    story.append(Paragraph("2026 Tatak Ormoc Consumers' Choice Awards", info_style))
    story.append(Spacer(1, 4 * mm))
    story.append(Paragraph("City Government of Ormoc", info_style))
    story.append(Spacer(1, 30 * mm))

    ver_style = ParagraphStyle("title_ver", fontName="Helvetica", fontSize=10,
                                leading=14, textColor=GRAY_TEXT, alignment=TA_CENTER)
    story.append(Paragraph("Version 2026.09", ver_style))
    story.append(PageBreak())

    # ═══════════════  TABLE OF CONTENTS  ═══════════════
    story.append(Spacer(1, 10 * mm))
    story.append(Paragraph("Table of Contents", styles["toc_title"]))
    story.append(Spacer(1, 5 * mm))

    toc_items = [
        ("1", "How the System Works"),
        ("2", "The Awards Process Flow"),
        ("3", "Registration Status Guide"),
        ("4", "Reviewing Registrations"),
        ("5", "Status Actions Explained"),
        ("6", "Sending Emails"),
        ("7", "Downloading Reports"),
        ("8", "Managing Awards"),
        ("9", "Audit Logs"),
    ]
    for num, title in toc_items:
        dotted = '.' * (80 - len(title))
        story.append(Paragraph(
            f'<b>{num}.</b>&nbsp;&nbsp;{title}&nbsp;'
            f'<font color="#9e9e9e">{dotted}</font>',
            styles["toc_entry"]))

    story.append(PageBreak())

    # ═══════════════  SECTION 1  ═══════════════
    story.append(SectionHeading("How the System Works", "1"))
    story.append(spacer(5))
    story.append(para(
        "The TOCCA Admin Portal manages the entire awards process — from receiving "
        "business registrations, reviewing and approving them, sending out notifications, "
        "all the way to public voting and results."
    ))
    story.append(para(
        "As an administrator, you have access to all modules: Registration review, "
        "Communications, Reports, Award management, and Audit Logs. This manual walks "
        "you through every task you'll perform."
    ))
    story.append(spacer(4))

    # ═══════════════  SECTION 2  ═══════════════
    story.append(SectionHeading("The Awards Process Flow", "2"))
    story.append(spacer(5))

    # Phase 1
    story.append(Paragraph("Phase 1: Registration", styles["phase_title"]))
    story.append(para(
        "Businesses register online through the public registration form. They fill in their "
        "business details, select their award categories, and upload their Mayor's Permit."
    ))
    story.append(CalloutBox("Your role: Review each registration.", "info"))
    story.append(spacer(4))

    # Phase 2
    story.append(Paragraph("Phase 2: TWG Validation", styles["phase_title"]))
    story.append(para(
        "Approved businesses are evaluated and scored by the Technical Working Group. "
        "The top 5 per category advance to public voting."
    ))
    story.append(CalloutBox("Your role: Send TWG notification emails.", "info"))
    story.append(spacer(4))

    # Phase 3
    story.append(Paragraph("Phase 3: Public Voting", styles["phase_title"]))
    story.append(para(
        "Top 5 businesses receive QR codes and voting links. The public votes online. "
        "TWG score (40%) + public votes (60%) = final winners."
    ))
    story.append(CalloutBox("Your role: Confirm for public voting, send QR emails.", "info"))
    story.append(spacer(4))

    # ═══════════════  SECTION 3  ═══════════════
    story.append(SectionHeading("Registration Status Guide", "3"))
    story.append(spacer(5))

    status_data = [
        ["Status", "What It Means", "What You Can Do"],
        ["Pending",
         "Just submitted. Waiting for admin review.",
         "Review it, then Approve, Reject, or mark Needs Info."],
        ["In Review",
         "An admin is currently looking at it.",
         "Same actions as Pending."],
        ["Needs Info",
         "Missing documents or details. An email was sent asking for more info.",
         "Once they respond, review again."],
        ["Approved",
         "Passed verification. Business record created for TWG scoring.",
         "Status is locked. Proceed to TWG validation."],
        ["Rejected",
         "Did not pass verification.",
         "Can be reversed — you can still Approve it if needed."],
    ]
    story.append(styled_table(status_data,
                              [30 * mm, 65 * mm, CONTENT_W - 95 * mm]))
    story.append(spacer(5))

    story.append(CalloutBox(
        "<b>Important:</b> Rejected registrations can be un-rejected. If you "
        "accidentally reject someone, just open their profile and click "
        '"Proceed to evaluation" to approve them. Only Approved and Merged '
        "statuses are permanently locked.",
        "warning"))
    story.append(spacer(4))

    # ═══════════════  SECTION 4  ═══════════════
    story.append(SectionHeading("Reviewing Registrations", "4"))
    story.append(spacer(5))
    story.append(para(
        "When a business registers, their application appears in the Registration module. "
        "Here's how to review one:"
    ))
    story.append(spacer(3))

    story.append(StepBox(1,
        "<b>Open the Registration List</b><br/>"
        "From the sidebar, go to <b>Registration</b>. You'll see a table of all "
        "registrations with their status. Use the filters at the top to narrow "
        "down by Status, Category, or specific Award."
    ))
    story.append(spacer(3))

    story.append(StepBox(2,
        "<b>Click on a Business Name</b><br/>"
        "Click any row to open that business's full profile. You'll see all their "
        "submitted details: business name, owner, contact info, address, uploaded "
        "documents, and which award categories they selected."
    ))
    story.append(spacer(3))

    story.append(StepBox(3,
        "<b>Review Their Details</b><br/>"
        "Check the following:<br/>"
        "• Is the business name correct and legitimate?<br/>"
        "• Did they upload a valid Mayor's Permit?<br/>"
        "• Are the selected award categories appropriate for their business type?<br/>"
        "• Is the email address valid?"
    ))
    story.append(spacer(3))

    story.append(StepBox(4,
        "<b>Take Action</b><br/>"
        "On the right side of the profile, you'll see the <b>Review Actions</b> panel "
        "with three buttons."
    ))
    story.append(spacer(4))

    action_data = [
        ["Button", "When to Use"],
        ["Proceed to evaluation",
         "Everything looks good. This approves the registration and creates the business record for TWG scoring."],
        ["Mark as Needs Information",
         "Something is missing or unclear. An email will be sent asking for more details."],
        ["Reject",
         "The registration does not qualify. An email notification is sent."],
    ]
    story.append(styled_table(action_data, [42 * mm, CONTENT_W - 42 * mm]))
    story.append(spacer(4))

    story.append(CalloutBox(
        '<b>Important:</b> "Proceed to evaluation" does NOT send an email '
        "automatically. It only creates the business record for TWG scoring. "
        "To notify businesses that they passed verification, use the "
        "<b>TWG Email Blast</b> page (see Section 6).",
        "warning"))
    story.append(spacer(4))

    # ═══════════════  SECTION 5  ═══════════════
    story.append(SectionHeading("Status Actions Explained", "5"))
    story.append(spacer(5))

    # Proceed to Evaluation
    story.append(SubHeading("Proceed to Evaluation (→ Approved)"))
    story.append(spacer(3))
    story.append(para("When you click this:"))
    story.append(Paragraph("•  The registration status changes to <b>Approved</b>",
                           styles["bullet"]))
    story.append(Paragraph("•  A business record is created in the system for TWG scoring",
                           styles["bullet"]))
    story.append(Paragraph("•  No email is sent (you send that separately)",
                           styles["bullet"]))
    story.append(spacer(2))
    story.append(CalloutBox(
        "Once approved, the status cannot be changed back.", "info"))
    story.append(spacer(4))

    # Mark as Needs Information
    story.append(SubHeading("Mark as Needs Information"))
    story.append(spacer(3))
    story.append(para(
        "Use this when the registration is incomplete. A compose window opens where "
        "you can write a message explaining what's missing. The system sends an email "
        "to the business. The business can then update their registration and you can "
        "review it again."
    ))
    story.append(spacer(4))

    # Reject
    story.append(SubHeading("Reject"))
    story.append(spacer(3))
    story.append(para(
        "Use this for registrations that don't qualify. A compose window opens for "
        "you to explain the reason. An email is sent to the business."
    ))
    story.append(CalloutBox(
        "If you accidentally reject a business, don't worry. Open their profile — "
        "the action buttons will still be active. You can click "
        '"Proceed to evaluation" to approve them.',
        "info"))
    story.append(spacer(4))

    # Removing Individual Awards
    story.append(SubHeading("Removing Individual Awards"))
    story.append(spacer(3))
    story.append(para(
        "Sometimes a business qualifies overall but one of their selected award "
        "categories doesn't fit. You can remove individual awards without rejecting "
        "the entire registration. The available reasons are:"
    ))
    story.append(spacer(2))

    removal_data = [
        ["Reason", "When to Use"],
        ["Wrong category or nature of business",
         "The award doesn't match what the business actually does"],
        ["Does not meet award criteria",
         "The business doesn't meet the specific requirements"],
        ["Does not offer the product / service",
         "The business doesn't actually sell or provide the product/service"],
        ["Requested by business",
         "The business owner asked to be removed from that category"],
        ["Other",
         "Any other reason (you'll need to specify)"],
    ]
    story.append(styled_table(removal_data, [55 * mm, CONTENT_W - 55 * mm]))
    story.append(spacer(4))

    # ═══════════════  SECTION 6  ═══════════════
    story.append(SectionHeading("Sending Emails", "6"))
    story.append(spacer(5))
    story.append(para("The system can send several types of emails:"))
    story.append(spacer(3))

    # TWG Notification
    story.append(SubHeading("TWG Notification Email Blast"))
    story.append(spacer(3))
    story.append(para(
        'This sends the official <b>"Your registration has been verified"</b> email '
        "to all approved businesses, informing them about the upcoming TWG Validation "
        "phase. It includes the TOCCA-2026.pdf attachment."
    ))
    story.append(spacer(2))
    story.append(para("<b>How to use:</b>", "body_bold"))

    story.append(StepBox(1,
        "<b>Open the TWG Email Blast page</b><br/>"
        "Go to <b>tocca_admin/twg_email_blast.php</b> in your browser. The page "
        "automatically loads all approved registrations."
    ))
    story.append(spacer(2))
    story.append(StepBox(2,
        "<b>Review the recipient list</b><br/>"
        "You'll see a table with every approved business, their email address, and "
        "contact person. Each row has a checkbox. Uncheck any business you don't want "
        "to email. Invalid emails are automatically disabled."
    ))
    story.append(spacer(2))
    story.append(StepBox(3,
        "<b>Preview the email</b><br/>"
        "Scroll down to see exactly what the email will look like. It uses the "
        "official TOCCA branded template."
    ))
    story.append(spacer(2))
    story.append(StepBox(4,
        '<b>Click "Send TWG Notification Emails"</b><br/>'
        "A confirmation dialog appears. Click OK to start sending. The system sends "
        "in batches of 5. You'll see a progress bar and each row updates to show "
        '"sent" (green) or "failed" (red) in real time.'
    ))
    story.append(spacer(4))

    # QR Code Email
    story.append(SubHeading("QR Code and Voting Link Email"))
    story.append(spacer(3))
    story.append(para(
        "This is sent later, only to businesses that make it into the <b>Top 5</b> after "
        "TWG scoring. It contains their unique QR code poster and voting link. Triggered "
        'from the business profile by clicking <b>"Confirm for public voting"</b>.'
    ))
    story.append(spacer(4))

    # Status Update Emails
    story.append(SubHeading("Status Update Emails"))
    story.append(spacer(3))
    story.append(para(
        'When you mark a registration as <b>"Needs Information"</b> or <b>"Reject"</b>, '
        "the system automatically opens an email composer. You write the message and it's "
        "sent using the branded TOCCA template."
    ))
    story.append(spacer(4))

    # ═══════════════  SECTION 7  ═══════════════
    story.append(SectionHeading("Downloading Reports", "7"))
    story.append(spacer(5))
    story.append(para(
        "You can download the registration list as <b>Excel</b>, <b>PDF</b>, or <b>CSV</b>."
    ))
    story.append(spacer(3))

    story.append(StepBox(1,
        "<b>Set your filters first</b><br/>"
        "On the Registration page, use the dropdown filters to select Status, "
        "Category, and Award."
    ))
    story.append(spacer(2))
    story.append(StepBox(2,
        '<b>Click the "Download" button</b><br/>'
        "A dialog appears with the options shown in the table below."
    ))
    story.append(spacer(3))

    dl_options = [
        ["Option", "What It Does"],
        ["Scope",
         '"All Categories" exports everything. "Selected Category &amp; Award" exports a specific category.'],
        ["Status",
         "Filter the export by status (Approved, Pending, etc.). Syncs from your page filter automatically."],
        ["File Format",
         "CSV (spreadsheets), Excel (formatted with colors), or PDF (print-ready)."],
    ]
    story.append(styled_table(dl_options, [30 * mm, CONTENT_W - 30 * mm]))
    story.append(spacer(4))

    story.append(StepBox(3,
        "<b>The report includes these columns:</b>"
    ))
    story.append(spacer(3))

    cols_data = [
        ["Column", "Description"],
        ["#", "Row number"],
        ["Business", "The registered business name"],
        ["Award(s)", "Which award categories the business registered for"],
        ["Email", "Business contact email"],
        ["Status", "Current registration status"],
    ]
    story.append(styled_table(cols_data, [30 * mm, CONTENT_W - 30 * mm]))
    story.append(spacer(3))

    story.append(CalloutBox(
        "If you filter by a specific category or award, the Award(s) column "
        "will only show awards within that filter.",
        "info"))
    story.append(spacer(4))

    # ═══════════════  SECTION 8  ═══════════════
    story.append(SectionHeading("Managing Awards", "8"))
    story.append(spacer(5))
    story.append(para(
        "Awards are organized by <b>Category</b> (like Food, Service, Feelings) and "
        "each category has specific award titles (like Best Halo-Halo, Best Café)."
    ))
    story.append(para(
        'During registration, businesses first select their <b>"Nature of Business"</b> '
        "(e.g. Restaurant, Cafe, Bakery). The system shows only the awards that apply to "
        "their business type. They can select multiple awards across different categories."
    ))
    story.append(para(
        "As an admin, you can remove specific awards from a business without rejecting "
        "their entire registration. This is useful when a business registered for an "
        "award that doesn't match what they actually offer."
    ))
    story.append(spacer(4))

    # ═══════════════  SECTION 9  ═══════════════
    story.append(SectionHeading("Audit Logs", "9"))
    story.append(spacer(5))
    story.append(para(
        "The <b>Admin History Log</b> tracks every action taken in the system for "
        "accountability and troubleshooting."
    ))
    story.append(spacer(3))

    story.append(SubHeading("What Gets Logged"))
    story.append(spacer(3))
    logged_data = [
        ["Action", "Description"],
        ["Status change",
         "When a registration is approved, rejected, or marked needs info"],
        ["Email sent",
         "Every email sent through the system"],
        ["TWG email blast",
         "Bulk notification emails with success/failure counts"],
        ["Award removal",
         "When an admin removes a specific award"],
        ["Report export",
         "When a registration list is downloaded"],
    ]
    story.append(styled_table(logged_data, [35 * mm, CONTENT_W - 35 * mm]))
    story.append(spacer(4))

    story.append(SubHeading("Filters Available"))
    story.append(spacer(3))
    filter_data = [
        ["Filter", "Use"],
        ["From / To", "Date range"],
        ["Module", "Area (Registration, Communications, Events)"],
        ["Action", "Specific action type"],
        ["Search", "Free-text search"],
    ]
    story.append(styled_table(filter_data, [30 * mm, CONTENT_W - 30 * mm]))
    story.append(spacer(4))

    story.append(CalloutBox(
        "<b>Note:</b> All timestamps are in Philippine time (Asia/Manila, UTC+8) "
        "regardless of server location.",
        "info"))
    story.append(spacer(8))

    # ── End footer ──
    story.append(HRFlowable(width="100%", thickness=0.5, color=GRAY_MED))
    story.append(spacer(3))
    end_style = ParagraphStyle("end", fontName="Helvetica", fontSize=9,
                                leading=13, textColor=GRAY_TEXT,
                                alignment=TA_CENTER)
    story.append(Paragraph("End of Manual  —  Version 2026.09", end_style))
    story.append(Paragraph(
        "TOCCA Admin Portal  •  City Government of Ormoc", end_style))

    # ── Build ──
    doc.build(story, onFirstPage=_title_page_template, onLaterPages=_header_footer)
    print(f"PDF generated: {OUTPUT_PATH}")


if __name__ == "__main__":
    build()
