@echo off
cd /d "%~dp0"
python jobs\init_db.py
python jobs\full_crawl.py
pause
