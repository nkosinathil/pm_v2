<!DOCTYPE html>
<html>
<head>
  <title>Forensic Acquisition Form</title>
  <style>
    body { font-family: Arial, sans-serif; margin: 20px; }
    h2 { background-color: #f2f2f2; padding: 10px; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
    td, th { border: 1px solid #ccc; padding: 8px; vertical-align: top; }
    textarea { width: 100%; height: 100px; }
    input[type="text"], input[type="date"], input[type="time"], input[type="radio"], input[type="file"] {
      width: 100%; padding: 5px;
    }
  </style>
</head>
<body>

<form method="post" action="save_acquisition.php" enctype="multipart/form-data">

<h2>Upload Imaging Log</h2>
<table>
  <tr><td>Select .log or .txt file:</td><td><input type="file" id="logFile" name="log_file" accept=".log,.txt"></td></tr>
</table>

<h2>1. Case Information</h2>
<table>
  <tr><td>Case Number:</td><td><input type="text" name="case_id" id="case_id" required></td></tr>
  <tr><td>Investigator Name:</td><td><input type="text" name="investigator_name" id="investigator_name"></td></tr>
  <tr><td>Date of Acquisition:</td><td><input type="date" name="acquisition_date" id="acquisition_date"></td></tr>
  <tr><td>Location of Acquisition:</td><td><input type="text" name="location" id="location"></td></tr>
  <tr><td>Imaging Tool Used:</td><td><input type="text" name="imaging_tool" id="imaging_tool"></td></tr>
</table>

<h2>2. Evidence Device Information</h2>
<table>
  <tr>
    <td>Device Type:</td>
    <td>
      <label><input type="checkbox" name="device_type[]" value="HDD"> HDD</label>
      <label><input type="checkbox" name="device_type[]" value="SSD"> SSD</label>
      <label><input type="checkbox" name="device_type[]" value="USB"> USB</label>
      <label><input type="checkbox" name="device_type[]" value="Mobile"> Mobile</label>
      <label><input type="checkbox" name="device_type[]" value="Other"> Other:</label>
      <input type="text" name="device_type_other" id="device_type_other">
    </td>
  </tr>
  <tr><td>Make & Model:</td><td><input type="text" name="make_model" id="make_model"></td></tr>
  <tr><td>Serial Number:</td><td><input type="text" name="serial_number" id="serial_number"></td></tr>
  <tr><td>Capacity:</td><td><input type="text" name="capacity" id="capacity"></td></tr>
  <tr>
    <td>Interface Type:</td>
    <td>
      <label><input type="checkbox" name="interface_type[]" value="SATA"> SATA</label>
      <label><input type="checkbox" name="interface_type[]" value="USB"> USB</label>
      <label><input type="checkbox" name="interface_type[]" value="NVMe"> NVMe</label>
      <label><input type="checkbox" name="interface_type[]" value="IDE"> IDE</label>
      <label><input type="checkbox" name="interface_type[]" value="Other"> Other:</label>
      <input type="text" name="interface_other" id="interface_other">
    </td>
  </tr>
  <tr>
    <td>Write Blocker Used:</td>
    <td>
      <label><input type="radio" name="write_blocker" value="Yes"> Yes</label>
      <label><input type="radio" name="write_blocker" value="No"> No</label>
      Reason (if No): <input type="text" name="write_blocker_reason" id="write_blocker_reason">
    </td>
  </tr>
</table>

<h2>3. Imaging Details</h2>
<table>
  <tr><td>Imaging Start Time:</td><td><input type="time" name="start_time" id="start_time"></td></tr>
  <tr><td>Imaging End Time:</td><td><input type="time" name="end_time" id="end_time"></td></tr>
  <tr><td>Imaging Tool & Version:</td><td><input type="text" name="tool_version" id="tool_version"></td></tr>
  <tr>
    <td>Imaging Format:</td>
    <td>
      <label><input type="checkbox" name="imaging_format[]" value="E01"> E01</label>
      <label><input type="checkbox" name="imaging_format[]" value="DD"> DD</label>
      <label><input type="checkbox" name="imaging_format[]" value="AFF"> AFF</label>
      <label><input type="checkbox" name="imaging_format[]" value="Other"> Other:</label>
      <input type="text" name="format_other" id="format_other">
    </td>
  </tr>
  <tr>
    <td>Compression Enabled:</td>
    <td>
      <label><input type="radio" name="compression" value="Yes"> Yes</label>
      <label><input type="radio" name="compression" value="No"> No</label>
    </td>
  </tr>
  <tr>
    <td>Verification Performed:</td>
    <td>
      <label><input type="radio" name="verification" value="Yes"> Yes</label>
      <label><input type="radio" name="verification" value="No"> No</label>
    </td>
  </tr>
  <tr><td>Comments/Notes:</td><td><textarea name="notes" id="notes"></textarea></td></tr>
</table>

<h2>4. Hash Verification</h2>
<table>
  <tr>
    <td>Hash Type:</td>
    <td>
      <label><input type="radio" name="hash_type" value="MD5"> MD5</label>
      <label><input type="radio" name="hash_type" value="SHA1"> SHA1</label>
      <label><input type="radio" name="hash_type" value="SHA256"> SHA256</label>
    </td>
  </tr>
  <tr><td>Original Device Hash:</td><td><input type="text" name="original_hash" id="original_hash"></td></tr>
  <tr><td>Image Hash:</td><td><input type="text" name="image_hash" id="image_hash"></td></tr>
  <tr>
    <td>Hashes Match?</td>
    <td>
      <label><input type="radio" name="hash_match" value="Yes"> Yes</label>
      <label><input type="radio" name="hash_match" value="No"> No</label>
    </td>
  </tr>
</table>

<h2>5. Chain of Custody at Acquisition</h2>
<table>
  <tr>
    <th>Role</th>
    <th>Name</th>
    <th>Date & Time</th>
    <th>Signature (typed)</th>
  </tr>
  <tr>
    <td>Acquired By</td>
    <td><input type="text" name="acquired_by_name" id="acquired_by_name"></td>
    <td><input type="text" name="acquired_by_date" id="acquired_by_date"></td>
    <td><input type="text" name="acquired_by_signature" id="acquired_by_signature"></td>
  </tr>
  <tr>
    <td>Received By</td>
    <td><input type="text" name="received_by_name" id="received_by_name"></td>
    <td><input type="text" name="received_by_date" id="received_by_date"></td>
    <td><input type="text" name="received_by_signature" id="received_by_signature"></td>
  </tr>
</table>

<h2>6. Additional Notes</h2>
<textarea name="additional_notes" id="additional_notes"></textarea>

<br>
<input type="submit" value="Submit Acquisition Form">

</form>

<script>
document.getElementById('logFile').addEventListener('change', function () {
  const file = this.files[0];
  if (!file) return;
  const reader = new FileReader();
  reader.onload = function (e) {
    const lines = e.target.result.split('\n');
    lines.forEach(line => {
      if (/Case:\s*(.*)/i.test(line)) document.getElementById('case_id').value = RegExp.$1.trim();
      else if (/Investigator:\s*(.*)/i.test(line)) document.getElementById('investigator_name').value = RegExp.$1.trim();
      else if (/Start Time:\s*(.*)/i.test(line)) document.getElementById('start_time').value = RegExp.$1.trim().substring(11, 16);
      else if (/End Time:\s*(.*)/i.test(line)) document.getElementById('end_time').value = RegExp.$1.trim().substring(11, 16);
      else if (/Device:\s*(.*)/i.test(line)) document.getElementById('make_model').value = RegExp.$1.trim();
      else if (/Serial:\s*(.*)/i.test(line)) document.getElementById('serial_number').value = RegExp.$1.trim();
      else if (/Capacity:\s*(.*)/i.test(line)) document.getElementById('capacity').value = RegExp.$1.trim();
      else if (/Imaging Tool:\s*(.*)/i.test(line)) document.getElementById('imaging_tool').value = RegExp.$1.trim();
      else if (/Tool Version:\s*(.*)/i.test(line)) document.getElementById('tool_version').value = RegExp.$1.trim();
      else if (/Location:\s*(.*)/i.test(line)) document.getElementById('location').value = RegExp.$1.trim();
      else if (/Write Blocker:\s*(Yes|No)/i.test(line)) {
        document.querySelector(`input[name=write_blocker][value="${RegExp.$1}"]`).checked = true;
      }
      else if (/Hash Type:\s*(.*)/i.test(line)) {
        const type = RegExp.$1.trim().toUpperCase();
        document.querySelector(`input[name=hash_type][value="${type}"]`).checked = true;
      }
      else if (/Original Hash:\s*(.*)/i.test(line)) document.getElementById('original_hash').value = RegExp.$1.trim();
      else if (/Image Hash:\s*(.*)/i.test(line)) document.getElementById('image_hash').value = RegExp.$1.trim();
      else if (/Hash Match:\s*(Yes|No)/i.test(line)) {
        document.querySelector(`input[name=hash_match][value="${RegExp.$1}"]`).checked = true;
      }
      else if (/Compression:\s*(Yes|No)/i.test(line)) {
        document.querySelector(`input[name=compression][value="${RegExp.$1}"]`).checked = true;
      }
      else if (/Verification:\s*(Yes|No)/i.test(line)) {
        document.querySelector(`input[name=verification][value="${RegExp.$1}"]`).checked = true;
      }
      else if (/Format:\s*(E01|DD|AFF|Other)/i.test(line)) {
        const val = RegExp.$1.toUpperCase();
        document.querySelector(`input[name='imaging_format[]'][value="${val}"]`).checked = true;
      }
      else if (/Device Type:\s*(.*)/i.test(line)) {
        const type = RegExp.$1.trim().toUpperCase();
        document.querySelectorAll(`input[name='device_type[]']`).forEach(cb => {
          if (type.includes(cb.value.toUpperCase())) cb.checked = true;
        });
      }
      else if (/Interface:\s*(.*)/i.test(line)) {
        const iface = RegExp.$1.trim().toUpperCase();
        document.querySelectorAll(`input[name='interface_type[]']`).forEach(cb => {
          if (iface.includes(cb.value.toUpperCase())) cb.checked = true;
        });
      }
    });
  };
  reader.readAsText(file);
});
</script>

</body>
</html>
