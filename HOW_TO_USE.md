## 🛠️ Installation & Setup

To fully experience this AI-powered Expense Tracker locally, you will need to set up both the web environment and the local AI engine.

### 📋 Prerequisites

1. **Ollama:** Since the AI analysis and natural language processing run entirely locally for your privacy, you must install [Ollama](https://ollama.com/) on your machine.
2. **Local Web Server:** A simple server environment to run the web app and handle the API (e.g., XAMPP, or VS Code "Live Server" extension).

### 🚀 Running the Project Locally

**Step 1: Download the Repository**
Clone this project to your local machine using Git, or download it as a ZIP file:
```bash
git clone [https://github.com/Mcl5323/Spend-Tracker-Web-Version.git](https://github.com/Mcl5323/Spend-Tracker-Web-Version.git)

Step 2: Configure Ollama (CORS Issue)
Because the frontend communicates directly with the local Ollama API, you must allow Cross-Origin Resource Sharing (CORS). Open your terminal or command prompt and set the environment variable:

Windows (Command Prompt): set OLLAMA_ORIGINS="*"

Mac/Linux (Terminal): export OLLAMA_ORIGINS="*"

Step 3: Start the AI Engine
In the same terminal where you set the CORS variable, start the required Ollama model. (Note: Replace llama3 with the exact model name used in the code, e.g., qwen or gemma):

Bash
ollama run llama3
(Keep this terminal running in the background so the web app can communicate with the AI).

Step 4: Launch the Web App

Move the downloaded project folder into your local server's root directory (for example, the htdocs folder if you are using XAMPP, to ensure any backend files in the api folder work correctly).

Start your local web server (e.g., start Apache in XAMPP).

Open your web browser and navigate to the project directory, specifically the public folder (e.g., http://localhost/Spend-Tracker-Web-Version/public/index.html).

Enjoy tracking and analyzing your expenses with AI!
