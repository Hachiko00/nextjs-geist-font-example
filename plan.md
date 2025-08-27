```markdown
# Detailed Implementation Plan for the LMS

This plan outlines the step‐by‐step modifications and file creations to integrate three unique features: QR Code Authentication, Badge Gamification, and Voice Feedback for teacher–student–guardian communication. Each section includes UI/UX design notes, error handling practices, and integration points within the existing Next.js project.

---

## 1. QR Code Authentication

### Dependencies and Setup
- **Dependency:** Add the “qrcode” library to package.json.
  - Install via:  
    `npm install qrcode`

### File: src/components/QRAuth.tsx
- **Purpose:** Display a generated QR code for user authentication.
- **Changes/Implementation:**
  - Import React, useState, useEffect, and the “qrcode” library.
  - Generate a QR code from a simulated authentication token.
  - Handle errors if QR generation fails.
  - Use a simple, centered layout with modern typography.
- **Example Code:**
  ```typescript
  import React, { useState, useEffect } from "react";
  import QRCode from "qrcode";

  const QRAuth = () => {
    const [qrCodeUrl, setQrCodeUrl] = useState("");
    const [error, setError] = useState("");

    useEffect(() => {
      const generateQR = async () => {
        try {
          // In a real scenario, use an actual user token
          const token = "user-authentication-token";
          const url = await QRCode.toDataURL(token);
          setQrCodeUrl(url);
        } catch (e) {
          setError("Failed to generate QR Code.");
        }
      };
      generateQR();
    }, []);

    if (error) return <div className="error">{error}</div>;

    return (
      <div
        className="qr-auth-container"
        style={{
          display: "flex",
          flexDirection: "column",
          alignItems: "center",
          padding: "2rem",
        }}
      >
        <h2>QR Code Authentication</h2>
        {qrCodeUrl ? (
          <img
            src={qrCodeUrl}
            alt="QR Code for user authentication"
            onError={(e) => {
              (e.target as HTMLImageElement).src =
                "https://placehold.co/300x300?text=QR+Code+Generation+Error";
            }}
          />
        ) : (
          <p>Generating QR Code...</p>
        )}
        <p>Scan the QR code with your mobile device to login.</p>
      </div>
    );
  };

  export default QRAuth;
  ```

### File: src/app/qr-auth.tsx
- **Purpose:** A dedicated page displaying the QRAuth component.
- **Example Code:**
  ```typescript
  import QRAuth from "../components/QRAuth";

  export default function QRAuthPage() {
    return (
      <main style={{ padding: "2rem" }}>
        <QRAuth />
      </main>
    );
  }
  ```

---

## 2. Badge Gamification

### UI and API Integration

#### File: src/app/dashboard/badges.tsx
- **Purpose:** Display a badge dashboard using the UI badge component.
- **Features:**
  - Fetch and display existing badges.
  - Provide a button to "award" a new badge.
  - Use the existing `src/components/ui/badge.tsx` component for rendering badges.
- **Example Code:**
  ```typescript
  import React, { useState, useEffect } from "react";
  import Badge from "../../components/ui/badge";

  const BadgesPage = () => {
    const [badges, setBadges] = useState<string[]>([]);
    const [error, setError] = useState("");

    useEffect(() => {
      async function fetchBadges() {
        try {
          const res = await fetch("/api/badges");
          if (!res.ok) throw new Error("Failed to fetch badges");
          const data = await res.json();
          setBadges(data.badges);
        } catch (e) {
          setError("Could not load badges.");
        }
      }
      fetchBadges();
    }, []);

    const awardBadge = async () => {
      try {
        const res = await fetch("/api/badges", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ badge: "Achievement Unlocked" }),
        });
        if (!res.ok) throw new Error("Failed to award badge");
        const data = await res.json();
        setBadges((prev) => [...prev, data.badge]);
      } catch (e) {
        setError("Error awarding badge.");
      }
    };

    return (
      <div
        className="badges-page"
        style={{ padding: "2rem", fontFamily: "Arial, sans-serif" }}
      >
        <h2>Badge Dashboard</h2>
        {error && <p className="error">{error}</p>}
        <div
          className="badges-list"
          style={{ display: "flex", flexWrap: "wrap", gap: "1rem" }}
        >
          {badges.length > 0 ? (
            badges.map((badge, index) => (
              <Badge key={index} className="badge-item">
                {badge}
              </Badge>
            ))
          ) : (
            <p>No badges awarded yet.</p>
          )}
        </div>
        <button
          onClick={awardBadge}
          style={{
            marginTop: "1rem",
            padding: "0.5rem 1rem",
            border: "none",
            backgroundColor: "#0070f3",
            color: "#fff",
            cursor: "pointer",
          }}
        >
          Award New Badge
        </button>
      </div>
    );
  };

  export default BadgesPage;
  ```

#### File: src/app/api/badges/route.ts
- **Purpose:** Create API endpoints (GET/POST) for badge management.
- **Error Handling:** Validate input and wrap operations with try/catch.
- **Example Code:**
  ```typescript
  import { NextResponse } from "next/server";

  let badges = ["Welcome Badge"];

  export async function GET() {
    return NextResponse.json({ badges });
  }

  export async function POST(request: Request) {
    try {
      const body = await request.json();
      const { badge } = body;
      if (!badge)
        return NextResponse.json({ error: "Badge not provided" }, { status: 400 });
      badges.push(badge);
      return NextResponse.json({ badge });
    } catch (error) {
      return NextResponse.json({ error: "Error awarding badge" }, { status: 500 });
    }
  }
  ```

---

## 3. Voice Feedback for Communication

### UI Component for Voice Recording

#### File: src/components/VoiceFeedback.tsx
- **Purpose:** Allow users to record, stop, play, and clear voice feedback.
- **Features:**
  - Initiate recording using MediaRecorder API.
  - Include error handling for microphone access issues.
  - Modern button design using clear typography and spacing.
- **Example Code:**
  ```typescript
  import React, { useState, useRef } from "react";

  const VoiceFeedback = () => {
    const [recording, setRecording] = useState(false);
    const [audioUrl, setAudioUrl] = useState("");
    const [error, setError] = useState("");
    const mediaRecorderRef = useRef<MediaRecorder | null>(null);
    const audioChunksRef = useRef<Blob[]>([]);

    const startRecording = async () => {
      try {
        const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        mediaRecorderRef.current = new MediaRecorder(stream);
        mediaRecorderRef.current.ondataavailable = (event) => {
          audioChunksRef.current.push(event.data);
        };
        mediaRecorderRef.current.onstop = () => {
          const audioBlob = new Blob(audioChunksRef.current, { type: "audio/webm" });
          const url = URL.createObjectURL(audioBlob);
          setAudioUrl(url);
          audioChunksRef.current = [];
        };
        mediaRecorderRef.current.start();
        setRecording(true);
      } catch (err) {
        setError("Microphone access denied or not available.");
      }
    };

    const stopRecording = () => {
      if (mediaRecorderRef.current) {
        mediaRecorderRef.current.stop();
        setRecording(false);
      }
    };

    const clearRecording = () => {
      setAudioUrl("");
    };

    return (
      <div
        className="voice-feedback-container"
        style={{
          padding: "2rem",
          display: "flex",
          flexDirection: "column",
          alignItems: "center",
          fontFamily: "Arial, sans-serif",
        }}
      >
        <h2>Voice Feedback</h2>
        {error && <div className="error" style={{ color: "red" }}>{error}</div>}
        <div className="controls" style={{ margin: "1rem 0" }}>
          {!recording && (
            <button
              onClick={startRecording}
              style={{
                marginRight: "1rem",
                padding: "0.5rem 1rem",
                backgroundColor: "#28a745",
                color: "#fff",
                border: "none",
                cursor: "pointer",
              }}
            >
              Start Recording
            </button>
          )}
          {recording && (
            <button
              onClick={stopRecording}
              style={{
                marginRight: "1rem",
                padding: "0.5rem 1rem",
                backgroundColor: "#dc3545",
                color: "#fff",
                border: "none",
                cursor: "pointer",
              }}
            >
              Stop Recording
            </button>
          )}
          {audioUrl && (
            <button
              onClick={clearRecording}
              style={{
                padding: "0.5rem 1rem",
                backgroundColor: "#6c757d",
                color: "#fff",
                border: "none",
                cursor: "pointer",
              }}
            >
              Clear Recording
            </button>
          )}
        </div>
        {audioUrl && (
          <audio controls style={{ marginTop: "1rem" }}>
            <source src={audioUrl} type="audio/webm" />
            Your browser does not support the audio element.
          </audio>
        )}
      </div>
    );
  };

  export default VoiceFeedback;
  ```

#### File: src/app/feedback/voice.tsx
- **Purpose:** Create a page dedicated to voice feedback.
- **Example Code:**
  ```typescript
  import VoiceFeedback from "../../components/VoiceFeedback";

  export default function VoiceFeedbackPage() {
    return (
      <main style={{ padding: "2rem" }}>
        <VoiceFeedback />
