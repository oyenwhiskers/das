import base64
import io
import json
import sys

from pypdf import PdfReader, PdfWriter


reader = PdfReader(sys.argv[1])
size = int(sys.argv[2])
batches = {}
for start in range(0, len(reader.pages), size):
    writer = PdfWriter()
    for page in reader.pages[start : start + size]:
        writer.add_page(page)
    output = io.BytesIO()
    writer.write(output)
    batches[start + 1] = base64.b64encode(output.getvalue()).decode('ascii')

json.dump({'count': len(reader.pages), 'batches': batches}, sys.stdout)
