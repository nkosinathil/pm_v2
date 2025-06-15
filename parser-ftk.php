<?php
// parser-ftk.php

function extractPattern($pattern, $text, $default = '') {
    if (preg_match($pattern, $text, $matches)) {
        return trim($matches[1]);
    }
    return $default;
}

function parseFtkImagerLog($file_content) {
    $metadata = [];
    $extracted_fields = [];
    $log_data = [];

    // Robust field extraction
    $metadata['Created By'] = extractPattern('/Created By\s*(.+)/i', $file_content);
    $metadata['Case Number'] = extractPattern('/Case Number:\s*(.+)/i', $file_content);
    $metadata['Evidence Number'] = extractPattern('/Evidence Number:\s*(.+)/i', $file_content);
    $metadata['Unique Description'] = extractPattern('/Unique description:\s*(.+)/i', $file_content);
    $metadata['Examiner'] = extractPattern('/Examiner:\s*(.+)/i', $file_content);
    $metadata['Notes'] = extractPattern('/Notes:\s*(.+)/i', $file_content);
    $metadata['Drive Model'] = extractPattern('/Drive Model:\s*(.+)/i', $file_content);
    $metadata['Drive Serial Number'] = extractPattern('/Drive Serial Number:\s*(.+)/i', $file_content);
    $metadata['Drive Interface'] = extractPattern('/Drive Interface(?:\s*Type)?:\s*(.+)/i', $file_content);
    $metadata['Source Size'] = extractPattern('/Source data size:\s*([\d,]+)\s*MB/i', $file_content);
    $metadata['MD5'] = extractPattern('/MD5(?:\s*checksum)?:\s*([a-f0-9]{32})/i', $file_content);
    $metadata['SHA1'] = extractPattern('/SHA1(?:\s*checksum)?:\s*([a-f0-9]{40})/i', $file_content);
    $metadata['SHA256'] = extractPattern('/SHA256(?:\s*checksum)?:\s*([a-f0-9]{64})/i', $file_content);
    $metadata['Acquisition Start'] = extractPattern('/Acquisition started:\s*(.+)/i', $file_content);
    $metadata['Acquisition End'] = extractPattern('/Acquisition finished:\s*(.+)/i', $file_content);
    $metadata['Verification Start'] = extractPattern('/Verification started:\s*(.+)/i', $file_content);
    $metadata['Verification End'] = extractPattern('/Verification finished:\s*(.+)/i', $file_content);
    $metadata['MD5 Verification'] = extractPattern('/MD5 checksum:\s*[a-f0-9]{32}\s*:\s*(.+)/i', $file_content);
    $metadata['SHA1 Verification'] = extractPattern('/SHA1 checksum:\s*[a-f0-9]{40}\s*:\s*(.+)/i', $file_content);
    $metadata['Tool Version'] = extractPattern('/FTK(?:®)? Imager\s+(\d+\.\d+\.\d+\.\d+)/i', $file_content);

    // Count image segments
    preg_match_all('/\.E\w{2,3}/i', $file_content, $matches);
    $metadata['Image Segments'] = count($matches[0]);

    // Build log_data array (same as before)
    if (!empty($metadata['Case Number'])) {
        $log_data['case_id'] = $metadata['Case Number'];
        $extracted_fields[] = 'case_id';
    }
    if (!empty($metadata['Evidence Number'])) {
        $log_data['evidence_number'] = $metadata['Evidence Number'];
        $extracted_fields[] = 'evidence_number';
    }
    if (!empty($metadata['Unique Description'])) {
        $log_data['unique_description'] = $metadata['Unique Description'];
        $extracted_fields[] = 'unique_description';
    }
    if (!empty($metadata['Examiner'])) {
        $log_data['investigator_name'] = $metadata['Examiner'];
        $extracted_fields[] = 'investigator_name';
    }
    if (!empty($metadata['Notes'])) {
        $log_data['notes'] = $metadata['Notes'];
        $log_data['description'] = $metadata['Notes'];
        $extracted_fields[] = 'notes';
        $extracted_fields[] = 'description';
    }
    if (!empty($metadata['Drive Model'])) {
        $log_data['make_model'] = $metadata['Drive Model'];
        $extracted_fields[] = 'make_model';
    }
    if (!empty($metadata['Drive Serial Number']) && $metadata['Drive Serial Number'] !== '0000000000000000') {
        $log_data['serial_number'] = $metadata['Drive Serial Number'];
        $extracted_fields[] = 'serial_number';
    }
    if (!empty($metadata['Drive Interface'])) {
        $interface = strtoupper($metadata['Drive Interface']);
        $log_data['drive_interface'] = $interface;
        $extracted_fields[] = 'drive_interface';
        if (strpos($interface, 'USB') !== false) $log_data['interface_type'] = ['USB'];
        elseif (strpos($interface, 'SATA') !== false) $log_data['interface_type'] = ['SATA'];
        elseif (strpos($interface, 'IDE') !== false) $log_data['interface_type'] = ['IDE'];
        elseif (strpos($interface, 'NVME') !== false) $log_data['interface_type'] = ['NVMe'];
        else {
            $log_data['interface_type'] = ['Other'];
            $log_data['interface_other'] = $interface;
            $extracted_fields[] = 'interface_other';
        }
        $extracted_fields[] = 'interface_type';
    }
    if (!empty($metadata['Source Size'])) {
        $log_data['capacity'] = str_replace(',', '', $metadata['Source Size']) . ' MB';
        $extracted_fields[] = 'capacity';
    }
    if (!empty($metadata['Tool Version'])) {
        $log_data['tool_version'] = 'FTK Imager ' . $metadata['Tool Version'];
        $log_data['imaging_tool'] = 'FTK Imager';
        $extracted_fields[] = 'tool_version';
        $extracted_fields[] = 'imaging_tool';
    }
    if (!empty($metadata['Acquisition Start'])) {
        $log_data['acquisition_date'] = date('Y-m-d', strtotime($metadata['Acquisition Start']));
        $log_data['start_time'] = date('H:i', strtotime($metadata['Acquisition Start']));
        $extracted_fields[] = 'acquisition_date';
        $extracted_fields[] = 'start_time';
    }
    if (!empty($metadata['Acquisition End'])) {
        $log_data['end_time'] = date('H:i', strtotime($metadata['Acquisition End']));
        $extracted_fields[] = 'end_time';
    }
    if (!empty($metadata['Verification Start'])) {
        $log_data['verification_start'] = date('H:i', strtotime($metadata['Verification Start']));
        $extracted_fields[] = 'verification_start';
    }
    if (!empty($metadata['Verification End'])) {
        $log_data['verification_end'] = date('H:i', strtotime($metadata['Verification End']));
        $extracted_fields[] = 'verification_end';
    }

    // HASHES
    if (!empty($metadata['MD5'])) {
        $log_data['md5_hash'] = $metadata['MD5'];
        $log_data['original_hash'] = $metadata['MD5'];
        $log_data['image_hash'] = $metadata['MD5'];
        $log_data['hash_type'] = 'MD5';
        $extracted_fields[] = 'md5_hash';
        $extracted_fields[] = 'original_hash';
        $extracted_fields[] = 'image_hash';
        $extracted_fields[] = 'hash_type';
    }
    if (!empty($metadata['SHA1'])) {
        $log_data['sha1_hash'] = $metadata['SHA1'];
        if (!empty($log_data['hash_type'])) {
            $log_data['hash_type'] .= '+SHA1';
        } else {
            $log_data['hash_type'] = 'SHA1';
            $log_data['original_hash'] = $metadata['SHA1'];
            $log_data['image_hash'] = $metadata['SHA1'];
        }
        $extracted_fields[] = 'sha1_hash';
        $extracted_fields[] = 'hash_type';
    }
    if (!empty($metadata['SHA256'])) {
        $log_data['sha256_hash'] = $metadata['SHA256'];
        if (!empty($log_data['hash_type'])) {
            $log_data['hash_type'] .= '+SHA256';
        } else {
            $log_data['hash_type'] = 'SHA256';
            $log_data['original_hash'] = $metadata['SHA256'];
            $log_data['image_hash'] = $metadata['SHA256'];
        }
        $extracted_fields[] = 'sha256_hash';
        $extracted_fields[] = 'hash_type';
    }

    if (!empty($metadata['MD5 Verification']) || !empty($metadata['SHA1 Verification'])) {
        $verified = strtolower($metadata['MD5 Verification'] ?? '') === 'verified' ||
                    strtolower($metadata['SHA1 Verification'] ?? '') === 'verified';
        $log_data['verification'] = 'Yes';
        $log_data['hash_match'] = $verified ? 'Yes' : 'No';
        $extracted_fields[] = 'verification';
        $extracted_fields[] = 'hash_match';
    }

    if (!empty($metadata['Image Segments'])) {
        $log_data['segment_count'] = $metadata['Image Segments'];
        $extracted_fields[] = 'segment_count';
    }

    $log_data['imaging_format'] = ['E01'];
    $extracted_fields[] = 'imaging_format';

    return [$log_data, $extracted_fields];
}
?>