import psycopg2
from psycopg2.extras import RealDictCursor

def get_db_connection():
    """Mengatur koneksi ke database Moodle (PostgreSQL)"""
    return psycopg2.connect(
        host="localhost",
        user="postgres",    
        password="", # isi dengan password PostgreSQL
        database="TA_LMS",  
    )

def fetch_all(query, params=None):
    """Menjalankan query dan mengambil banyak baris data"""
    conn = get_db_connection()
    try:
        with conn.cursor(cursor_factory=RealDictCursor) as cursor:
            cursor.execute(query, params)
            result = cursor.fetchall()
            return result
    finally:
        conn.close()

def fetch_one(query, params=None):
    """Menjalankan query dan mengambil satu baris data"""
    conn = get_db_connection()
    try:
        with conn.cursor(cursor_factory=RealDictCursor) as cursor:
            cursor.execute(query, params)
            result = cursor.fetchone()
            return result
    finally:
        conn.close()