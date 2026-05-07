<?php
// ============================================================
//  ★  Spend Tracker — AI 配置文件
//  ★  重要：此文件已被 .gitignore 忽略，绝不可提交到 Git！
// ============================================================
//
//  如何获取免费的 Google Gemini API Key：
//  1. 访问 https://aistudio.google.com/app/apikey
//  2. 点击 "Create API key" 并复制密钥
//  3. 将密钥粘贴到下方 GEMINI_API_KEY 的值中
//
// ============================================================

return [

    // ── Gemini API Key ──────────────────────────────────────
    // 将 YOUR_GEMINI_API_KEY_HERE 替换为你真实的 Key
    'GEMINI_API_KEY' => 'YOUR_GEMINI_API_KEY_HERE',

    // ── 模型与 Endpoint（通常无需修改）────────────────────────
    'GEMINI_MODEL'   => 'gemini-1.5-flash',
    'GEMINI_API_URL' => 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent',

];
