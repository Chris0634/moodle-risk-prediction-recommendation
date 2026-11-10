from db_connection import fetch_all 
import pandas as pd
import numpy as np
import joblib
import psycopg2
import time
import json
from typing import Optional
from fastapi import FastAPI, HTTPException
from pydantic import BaseModel
from bs4 import BeautifulSoup
from sentence_transformers import SentenceTransformer
from sklearn.metrics.pairwise import cosine_similarity
import shap

app = FastAPI(title="Smart LMSX Prediction API")

DB_CONFIG = {
    "dbname": "TA_LMS",
    "user": "postgres",
    "password": "060304Ckw*", 
    "host": "localhost",
    "port": "5432"
}

try:
    rf_model = joblib.load('smart_lmsx_model_final.pkl')
    scaler = joblib.load('scaler.pkl')
    explainer = shap.TreeExplainer(rf_model)
    print("✅ Model, Scaler, dan XAI Explainer berhasil dimuat.")
except Exception as e:
    print(f"❌ Error loading files: {e}")

class MoodleActivity(BaseModel):
    userid: Optional[int] = 0
    courseid: Optional[int] = 0
    eventname: Optional[str] = ""
    time: Optional[int] = 0
    action: Optional[str] = ""

def get_real_moodle_data(userid, courseid):
    try:
        conn = psycopg2.connect(**DB_CONFIG)
        cur = conn.cursor()
        
        query = f"""
            SELECT 
                -- 0. Total Clicks (Log)
                (SELECT COUNT(*) FROM mdl_logstore_standard_log WHERE userid = {userid} AND courseid = {courseid}) as clicks,
                
                -- 1. Total HVP Interactions
                (SELECT COUNT(hxr.id) FROM mdl_hvp_xapi_results hxr JOIN mdl_hvp h ON hxr.content_id = h.id WHERE hxr.user_id = {userid} AND h.course = {courseid}) as hvp_count,
                
                -- 2. Total Forum Activity (Gabungan Posts & Discussions)
                (
                    (SELECT COUNT(*) FROM mdl_forum_posts p JOIN mdl_forum_discussions d ON p.discussion = d.id WHERE p.userid = {userid} AND d.course = {courseid})
                    +
                    (SELECT COUNT(*) FROM mdl_forum_discussions WHERE userid = {userid} AND course = {courseid})
                ) as forum_activity,
                
                -- 3. Rata-rata Nilai Tugas Murni
                (SELECT COALESCE(AVG(gg.finalgrade), 0) FROM mdl_grade_grades gg JOIN mdl_grade_items gi ON gg.itemid = gi.id WHERE gg.userid = {userid} AND gi.courseid = {courseid} AND gi.itemmodule = 'assign') as assign_grade,
                
                -- 4. Rata-rata Nilai Kuis Murni
                (SELECT COALESCE(AVG(gg.finalgrade), 0) FROM mdl_grade_grades gg JOIN mdl_grade_items gi ON gg.itemid = gi.id WHERE gg.userid = {userid} AND gi.courseid = {courseid} AND gi.itemmodule = 'quiz') as quiz_grade,
                
                -- 5. Rata-rata Nilai Forum Murni (Normalisasi 100% dan Excluded != 1)
                (SELECT COALESCE(AVG((gg.finalgrade / NULLIF(gi.grademax, 0)) * 100), 0) FROM mdl_grade_grades gg JOIN mdl_grade_items gi ON gg.itemid = gi.id WHERE gg.userid = {userid} AND gi.courseid = {courseid} AND gi.itemmodule = 'forum' AND gg.excluded != 1) as forum_grade,
                
                -- 6. Total Assignments (Reference - KHUSUS UNTUK GUARDRAILS)
                (SELECT COUNT(*) FROM mdl_assign WHERE course = {courseid}) as total_assign_ref,
                
                -- 7. Total Submissions (KHUSUS UNTUK GUARDRAILS)
                (SELECT COUNT(*) FROM mdl_assign_submission WHERE userid = {userid} AND assignment IN (SELECT id FROM mdl_assign WHERE course = {courseid})) as total_sub
        """
        
        cur.execute(query)
        row = cur.fetchone()
        cur.close()
        conn.close()
        return row
    except Exception as e:
        print(f"❌ Database Error: {e}")
        return None
    
def save_prediction_to_db(userid, courseid, risk_score, is_at_risk, status, risk_breakdown):
    try:
        conn = psycopg2.connect(**DB_CONFIG)
        cur = conn.cursor()
        current_time = int(time.time())

        breakdown_json = json.dumps(risk_breakdown)

        check_query = "SELECT id FROM mdl_local_smart_lmsx_pred WHERE userid = %s AND courseid = %s"
        cur.execute(check_query, (userid, courseid))
        record = cur.fetchone()
        
        if record:
            update_query = """
                UPDATE mdl_local_smart_lmsx_pred 
                SET risk_score = %s, is_at_risk = %s, status = %s, risk_breakdown = %s, timemodified = %s 
                WHERE id = %s
            """
            cur.execute(update_query, (float(risk_score), int(is_at_risk), status, breakdown_json, current_time, record[0]))
        else:
            insert_query = """
                INSERT INTO mdl_local_smart_lmsx_pred (userid, courseid, risk_score, is_at_risk, status, risk_breakdown, timemodified) 
                VALUES (%s, %s, %s, %s, %s, %s, %s)
            """
            cur.execute(insert_query, (userid, courseid, float(risk_score), int(is_at_risk), status, breakdown_json, current_time))

        conn.commit()
        cur.close()
        conn.close()
        print(f"✅ Prediksi & Breakdown untuk User {userid} berhasil disimpan.")
    except Exception as e:
        print(f"❌ Error DB Save: {e}")


def get_student_text_signals(userid: int, courseid: int):
    """
    Mengambil dan menggabungkan sinyal teks dari forum dan feedback dosen
    sebagai input untuk model rekomendasi SBERT.
    """
    try:
        query_forum = """
            SELECT p.message 
            FROM mdl_forum_posts p
            JOIN mdl_forum_discussions d ON p.discussion = d.id
            WHERE p.userid = %s AND d.course = %s
            ORDER BY p.created DESC LIMIT 3
        """
        
        query_feedback = """
            SELECT afc.commenttext
            FROM mdl_assignfeedback_comments afc
            JOIN mdl_assign_submission asub ON afc.assignment = asub.assignment 
            AND afc.grade = asub.id
            JOIN mdl_assign a ON afc.assignment = a.id
            WHERE asub.userid = %s AND a.course = %s
            ORDER BY afc.id DESC LIMIT 2
        """

        forum_posts = fetch_all(query_forum, (userid, courseid))
        feedbacks = fetch_all(query_feedback, (userid, courseid))

        combined_text = ""
        
        if forum_posts:
            for post in forum_posts:
                combined_text += f" {post['message']}"
        
        if feedbacks:
            for fb in feedbacks:
                combined_text += f" {fb['commenttext']}"

        if combined_text.strip():
            clean_text = BeautifulSoup(combined_text, "html.parser").get_text()
            return clean_text.strip()
        
        return ""

    except Exception as e:
        print(f"❌ Error fetching text signals: {e}")
        return ""

try:
    recom_data = joblib.load('smart_lmsx_recom_engine.pkl')
    recom_catalog = recom_data['catalog']
    recom_embeddings = recom_data['embeddings']
    
    model_name = recom_data['model_config']['model_name']
    sbert_model = SentenceTransformer(model_name)
    print("✅ SBERT Recom Engine & Model Berhasil Dimuat.")
except Exception as e:
    print(f"❌ Error loading Recom Engine: {e}")

def get_recommendation_sbert(student_text, courseid, prob_risk):
    course_mapping = {
        5322: 5321,
    }
    
    search_courses = [courseid]
    if courseid in course_mapping:
        search_courses.append(course_mapping[courseid])

    indeks_valid = recom_catalog.index[recom_catalog['course'].isin(search_courses)].tolist()
    
    if len(indeks_valid) == 0:
        return "Silakan ikuti urutan materi di halaman utama.", False
        
    current_catalog = recom_catalog.iloc[indeks_valid].reset_index(drop=True)
    current_embeddings = recom_embeddings[indeks_valid]

    if not student_text or len(student_text.strip()) < 5:
        return current_catalog.iloc[0]['name'], False

    try:
        query_embedding = sbert_model.encode([student_text])
        similarities = cosine_similarity(query_embedding, current_embeddings)[0]
        best_match_idx = np.argmax(similarities)
        
        if similarities[best_match_idx] < 0.45:
            return "Tinjau kembali materi utama pekan ini.", False
            
        return current_catalog.iloc[best_match_idx]['name'], True
    except Exception as e:
        print(f"❌ Error SBERT inference: {e}")
        return "Silakan tinjau kembali materi pekan ini.", False
                
def save_recommendation_to_db(userid, courseid, material_name, reason):
    try:
        conn = psycopg2.connect(**DB_CONFIG)
        cur = conn.cursor()
        current_time = int(time.time())
        
        check_query = "SELECT id FROM mdl_local_smart_lmsx_rec WHERE userid = %s AND courseid = %s"
        cur.execute(check_query, (userid, courseid))
        record = cur.fetchone()
        
        if record:
            update_query = """
                UPDATE mdl_local_smart_lmsx_rec 
                SET material_name = %s, reason = %s, timemodified = %s 
                WHERE id = %s
            """
            cur.execute(update_query, (str(material_name), str(reason), current_time, record[0]))
        else:
            insert_query = """
                INSERT INTO mdl_local_smart_lmsx_rec (userid, courseid, material_name, reason, timemodified) 
                VALUES (%s, %s, %s, %s, %s)
            """
            cur.execute(insert_query, (userid, courseid, str(material_name), str(reason), current_time))
            
        conn.commit()
        cur.close()
        conn.close()
        print(f"✅ Rekomendasi untuk User {userid} berhasil disimpan.")
    except Exception as e:
        print(f"❌ Gagal simpan rekomendasi: {e}")

@app.post("/predict")
async def predict_risk(data: MoodleActivity):
    try:
        stats = get_real_moodle_data(data.userid, data.courseid)

        if not stats:
            raise HTTPException(status_code=404, detail="Data Moodle tidak ditemukan.")

        raw_features = {
            'total_clicks': float(stats[0] or 0),
            'total_hvp_interactions': float(stats[1] or 0),
            'total_forum_activity': float(stats[2] or 0),
            'assign_grade': float(stats[3] or 0),
            'quiz_grade': float(stats[4] or 0),
            'forum_grade': float(stats[5] or 0)
        }

        df_input = pd.DataFrame([raw_features])
        input_scaled = scaler.transform(df_input)

        prediction = int(rf_model.predict(input_scaled)[0])
        prob_risk = rf_model.predict_proba(input_scaled)[0][1]

        total_assign = float(stats[6] or 0)
        total_sub = float(stats[7] or 0)
        
        completion_rate = total_sub / total_assign if total_assign > 0 else 1.0
        
        if completion_rate < 0.30:
            prob_risk = max(prob_risk, 0.85)
            prediction = 1

        if prob_risk > 0.7:
            message = "Risiko Tinggi"
        elif prob_risk > 0.35:
            message = "Risiko Sedang"
        else:
            message = "Risiko Rendah"

        shap_values = explainer.shap_values(input_scaled)

        if isinstance(shap_values, list):
            contributions = shap_values[1][0]
        elif hasattr(shap_values, 'shape') and len(shap_values.shape) == 3:
            contributions = shap_values[0, :, 1]
        else:
            contributions = shap_values[0]

        feature_names = [
            'Total Interaksi Log', 
            'Total Interaksi H5P', 
            'Total Keaktifan Forum', 
            'Rata-rata Nilai Tugas', 
            'Rata-rata Nilai Kuis',
            'Rata-rata Nilai Forum'
        ]

        breakdown_dict = {
            feature_names[i]: float(contributions[i]) 
            for i in range(len(feature_names)) 
            if contributions[i] > 0
        }

        text_signals = get_student_text_signals(data.userid, data.courseid)
        rec_material, is_nlp_match = get_recommendation_sbert(text_signals, data.courseid, prob_risk)

        save_prediction_to_db(data.userid, data.courseid, prob_risk, prediction, message, breakdown_dict)
        save_recommendation_to_db(data.userid, data.courseid, rec_material, "Berdasarkan interaksi mahasiswa")

        return {
            "is_at_risk": int(prediction),
            "risk_score": float(prob_risk),
            "status": message,
            "ai_material_suggestion": rec_material,
            "risk_breakdown": breakdown_dict
        }

    except Exception as e:
        import traceback
        print("\n" + "="*50)
        print("❌ CRITICAL ERROR DI PREDICT API:")
        traceback.print_exc()
        print("="*50 + "\n")
        raise HTTPException(status_code=500, detail=str(e))

if __name__ == "__main__":
    import uvicorn
    uvicorn.run(app, host="127.0.0.1", port=8000)