import fitz
import os
import sys

pdf_path = "uploads/manuscript_1782092424_7622.pdf"

if not os.path.exists(pdf_path):
    print(f"ERROR: PDF file {pdf_path} not found.")
    sys.exit(1)

try:
    doc = fitz.open(pdf_path)
    if len(doc) <= 1:
         print("ERROR: PDF has only 1 page, cannot redact page 2.")
         sys.exit(1)
         
    page = doc[1] # Page 2 is index 1
    width = page.rect.width
    height = page.rect.height

    # Define the redaction rectangle covering title and authors (y = 60 to 320)
    rect = fitz.Rect(0, 60, width, 320)
    page.add_redact_annot(rect, fill=(1, 1, 1))
    page.apply_redactions()
    
    # Save the cleaned PDF back to itself
    temp_out = pdf_path + ".tmp"
    doc.save(temp_out, garbage=4, deflate=True, clean=True)
    doc.close()
    
    # Overwrite the original
    os.replace(temp_out, pdf_path)
    print(f"SUCCESS: Successfully redacted the line and updated {pdf_path}!")
except Exception as e:
    print(f"ERROR: {e}")
    sys.exit(1)
