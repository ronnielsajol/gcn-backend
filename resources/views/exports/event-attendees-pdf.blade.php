<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <title>Event Attendees - {{ $event->name }}</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
            font-size: 12px;
            line-height: 1.4;
        }

        .header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #333;
        }

        .header h1 {
            margin: 0;
            color: #333;
            font-size: 24px;
        }

        .header h2 {
            margin: 5px 0;
            color: #666;
            font-size: 18px;
            font-weight: normal;
        }

        .event-info {
            background-color: #f8f9fa;
            padding: 15px;
            margin-bottom: 30px;
            border-radius: 5px;
        }

        .event-info table {
            width: 100%;
            border-collapse: collapse;
        }

        .event-info td {
            padding: 5px 10px;
            border-bottom: 1px solid #ddd;
        }

        .event-info td:first-child {
            font-weight: bold;
            width: 30%;
            color: #333;
        }

        .attendees-grid {
            /* Simplified grid - no flexbox for speed */
            width: 100%;
        }

        .attendee-card {
            width: 48%;
            border: 1px solid #ddd;
            border-radius: 4px;
            /* Reduced for speed */
            padding: 10px;
            /* Reduced padding */
            margin-bottom: 10px;
            background-color: #fff;
            page-break-inside: avoid;
            float: left;
            margin-right: 2%;
        }

        .attendee-card:nth-child(2n) {
            margin-right: 0;
        }

        .attendee-header {
            overflow: hidden;
            /* clearfix */
            margin-bottom: 8px;
        }

        .profile-image {
            width: 50px;
            /* Smaller for speed */
            height: 50px;
            border-radius: 50%;
            object-fit: cover;
            margin-right: 10px;
            border: 1px solid #ddd;
            float: left;
        }

        .profile-placeholder {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: #e9ecef;
            margin-right: 10px;
            border: 1px solid #ddd;
            color: #6c757d;
            font-weight: bold;
            font-size: 16px;
            /* Smaller font */
            text-align: center;
            line-height: 50px;
            /* Simple vertical centering */
            float: left;
        }

        .attendee-name {
            margin-left: 65px;
            /* Space for image + margin */
            padding-top: 5px;
        }

        .attendee-name h3 {
            margin: 0 0 2px 0;
            font-size: 14px;
            /* Smaller font */
            color: #333;
        }

        .attendee-id {
            font-size: 10px;
            color: #666;
            margin-top: 1px;
        }

        .attendee-details {
            clear: both;
            margin-top: 8px;
        }

        .detail-row {
            margin-bottom: 3px;
            /* Reduced spacing */
            font-size: 10px;
            /* Smaller font for speed */
        }

        .detail-label {
            font-weight: bold;
            display: inline-block;
            width: 70px;
            color: #555;
        }

        .detail-value {
            color: #333;
        }

        .footer {
            margin-top: 30px;
            text-align: center;
            font-size: 10px;
            color: #666;
            border-top: 1px solid #ddd;
            padding-top: 15px;
        }

        .page-break {
            page-break-before: always;
        }

        @media print {
            .attendee-card {
                width: 48%;
                break-inside: avoid;
            }
        }
    </style>
</head>

<body>
    <div class="header">
        <h1>Event Attendees Report</h1>
        <h2>{{ $event->name }}</h2>
    </div>

    <div class="event-info">
        <table>
            <tr>
                <td>Event ID:</td>
                <td>{{ $event->id }}</td>
            </tr>
            <tr>
                <td>Event Name:</td>
                <td>{{ $event->name }}</td>
            </tr>
            @if ($event->description)
            <tr>
                <td>Description:</td>
                <td>{{ $event->description }}</td>
            </tr>
            @endif
            <tr>
                <td>Start Time:</td>
                <td>{{ $event->start_time ? $event->start_time->format('M d, Y - g:i A') : 'N/A' }}</td>
            </tr>
            <tr>
                <td>End Time:</td>
                <td>{{ $event->end_time ? $event->end_time->format('M d, Y - g:i A') : 'N/A' }}</td>
            </tr>
            <tr>
                <td>Status:</td>
                <td style="text-transform: capitalize;">{{ $event->status }}</td>
            </tr>
            <tr>
                <td>Total Attendees:</td>
                <td><strong>{{ $attendees->count() }}</strong></td>
            </tr>
            <tr>
                <td>Generated On:</td>
                <td>{{ now()->format('M d, Y - g:i A') }}</td>
            </tr>
        </table>
    </div>

    <div class="attendees-grid">
        @foreach ($attendees as $index => $user)
        <div class="attendee-card">
            <div class="attendee-header">
                @php
                // Enhanced image handling for PDF compatibility with multiple fallback strategies
                $showImage = false;
                $imageSrc = null;
                $debugInfo = '';

                if ($user->profile_image) {
                try {
                if (str_starts_with($user->profile_image, 'http')) {
                // Handle HTTP URLs directly
                $imageSrc = $user->profile_image;
                $showImage = true;
                $debugInfo = 'HTTP URL';
                } else {
                // Handle local files with multiple strategies
                $relativePath = str_starts_with($user->profile_image, 'storage/')
                ? $user->profile_image
                : 'storage/' . $user->profile_image;

                $absolutePath = public_path($relativePath);

                if (file_exists($absolutePath)) {
                $fileSize = filesize($absolutePath);
                $debugInfo = 'File exists (' . number_format($fileSize / 1024, 1) . 'KB)';

                // Always try base64 for PDF reliability (DOMPDF works best with base64)
                if ($fileSize && $fileSize < 500000) {
                    // 500KB limit
                    $imageContent=file_get_contents($absolutePath);
                    $imageInfo=getimagesize($absolutePath);
                    if ($imageContent && $imageInfo) {
                    $mimeType=$imageInfo['mime'];
                    $imageSrc='data:' . $mimeType . ';base64,' . base64_encode($imageContent);
                    $showImage=true;
                    $debugInfo='Base64 (' . number_format($fileSize / 1024, 1) . 'KB)' ;
                    }
                    } else {
                    $debugInfo='File too large (' . number_format($fileSize / 1024, 1) . 'KB)' ;
                    }
                    } else {
                    $debugInfo='File not found: ' . $absolutePath;
                    }
                    }
                    } catch (Exception $e) {
                    $debugInfo='Error: ' . $e->getMessage();
                    }
                    } else {
                    $debugInfo = 'No profile image';
                    }
                    @endphp

                    @if ($showImage && $imageSrc)
                    <img src="{{ $imageSrc }}" alt="Profile" class="profile-image">
                    {{-- Debug: Show detailed image processing info --}}
                    @if (config('app.debug'))
                    <small
                        style="font-size: 6px; color: #999; position: absolute; margin-top: 52px; max-width: 50px; word-wrap: break-word;">
                        {{ $debugInfo }}
                    </small>
                    @endif
                    @else
                    <div class="profile-placeholder">
                        {{ strtoupper(substr($user->first_name, 0, 1) . substr($user->last_name, 0, 1)) }}
                    </div>
                    {{-- Debug: Show why no image was displayed --}}
                    @if (config('app.debug'))
                    <small
                        style="font-size: 6px; color: #999; position: absolute; margin-top: 52px; max-width: 50px; word-wrap: break-word;">
                        {{ $debugInfo }}
                    </small>
                    @endif
                    @endif

                    <div class="attendee-name">
                        <h3>{{ strtoupper($user->first_name) }} {{ strtoupper($user->last_name) }}</h3>
                        <div class="attendee-id">ID: {{ $user->id }}</div>
                    </div>
            </div>

            <div class="attendee-details">
                @if ($user->email)
                <div class="detail-row">
                    <span class="detail-label">Email:</span>
                    <span class="detail-value">{{ $user->email }}</span>
                </div>
                @endif

                <div class="detail-row">
                    <span class="detail-label">Contact:</span>
                    <span class="detail-value">{{ $user->mobile_number ?? 'N/A' }}</span>
                </div>

                <div class="detail-row">
                    <span class="detail-label">Gender:</span>
                    <span class="detail-value">{{ ucfirst($user->gender) }}</span>
                </div>

                @if ($user->religion)
                <div class="detail-row">
                    <span class="detail-label">Religion:</span>
                    <span class="detail-value">{{ $user->religion }}</span>
                </div>
                @endif

                @if ($user->home_address)
                <div class="detail-row">
                    <span class="detail-label">Address:</span>
                    <span class="detail-value">{{ $user->home_address }}</span>
                </div>
                @endif
            </div>
        </div>

        {{-- Add page break every 8 cards --}}
        @if (($index + 1) % 8 == 0 && !$loop->last)
        <div class="page-break"></div>
        @endif
        @endforeach

        <!-- Clearfix for float layout -->
        <div style="clear: both;"></div>
    </div>

    <div class="footer">
        <p>Generated on {{ now()->format('F d, Y \a\t g:i A') }} | Total Attendees: {{ $attendees->count() }}</p>
        <p>Event: {{ $event->name }} (ID: {{ $event->id }})</p>
    </div>
</body>

</html>