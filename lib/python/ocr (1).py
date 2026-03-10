# Simulated OCR Process

# Step 1: Load dummy 'image' (simulated)
document_image = "dummy_document.png"  # This would be the uploaded file

# Step 2: Simulate extracted text
# This simulates the output that OCR like Tesseract would generate
simulated_extracted_text = """
City Human Resource Management Office
Document Title: Leave Application Form
Employee: Juan Dela Cruz
Date Submitted: 2025-05-01
Status: Pending Approval
"""

# Step 3: Simulate post-processing
def clean_text(text):
    # Simulate cleaning or structuring the text
    lines = text.strip().split('\n')
    data = {}
    for line in lines:
        if ':' in line:
            key, value = line.split(':', 1)
            data[key.strip()] = value.strip()
    return data

# Step 4: Use the simulated OCR output
structured_data = clean_text(simulated_extracted_text)

# Step 5: Display structured output (just like the system would use it)
print("Simulated OCR Result:")
for key, value in structured_data.items():
    print(f"{key}: {value}")

from flask import Flask, jsonify

app = Flask(__name__)

@app.route('/ocr', methods=['POST'])
def simulate_ocr():
    # Simulate OCR output
    simulated_text = """
    City Human Resource Management Office
    Document Title: Leave Application Form
    Employee: Juan Dela Cruz
    Date Submitted: 2025-05-01
    Status: Pending Approval
    """
    lines = simulated_text.strip().split('\n')
    data = {}
    for line in lines:
        if ':' in line:
            key, value = line.split(':', 1)
            data[key.strip()] = value.strip()
    return jsonify(data)

if __name__ == '__main__':
    app.run(debug=True)
