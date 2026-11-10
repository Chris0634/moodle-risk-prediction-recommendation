<?php
function local_smart_lmsx_extend_navigation_course($navigation, $course, $context) {
    if (has_capability('moodle/course:manageactivities', $context)) {
        $url = new moodle_url('/local/smart_lmsx/report.php', array('id' => $course->id));
        $navigation->add(
            'Smart LMSX Prediction', 
            $url, 
            navigation_node::TYPE_SETTING, 
            null, 
            'smart_lmsx_report', 
            new pix_icon('i/report', '')
        );
    }
}

function local_smart_lmsx_get_user_risk($courseid, $userid) {
    global $DB;

    $record = $DB->get_record_sql("
        SELECT risk_score 
        FROM {local_smart_lmsx_pred} 
        WHERE userid = ? AND courseid = ? 
        ORDER BY timemodified DESC LIMIT 1", 
        array($userid, $courseid)
    );

    if (!$record) {
        return null;
    }

    $score = $record->risk_score * 100; 
    
    $class = 'success'; 
    if ($score > 70) $class = 'danger'; 
    else if ($score > 35) $class = 'warning'; 

    return [
        'score' => round($score, 1),
        'class' => $class,
    ];
}

function local_smart_lmsx_display_widget($courseid) {
    global $USER, $DB;

    // Hanya untuk mahasiswa
    $context = context_course::instance($courseid);
    if (has_capability('moodle/course:update', $context)) {
        return; 
    }

    $record = $DB->get_record_sql("
        SELECT risk_score, status, risk_breakdown 
        FROM {local_smart_lmsx_pred} 
        WHERE userid = ? AND courseid = ? 
        ORDER BY timemodified DESC LIMIT 1", 
        array($USER->id, $courseid)
    );

    $rec = $DB->get_record_sql("
        SELECT material_name, reason 
        FROM {local_smart_lmsx_rec} 
        WHERE userid = ? AND courseid = ? 
        ORDER BY timemodified DESC LIMIT 1", 
        array($USER->id, $courseid)
    );

    if ($record) {
        $score = round($record->risk_score * 100, 1);
        $recommendation = $record->status;
        
        $class = 'success'; 
        if ($score > 70) $class = 'danger';
        else if ($score > 35) $class = 'warning';

        echo '
        <div class="alert alert-'.$class.' d-flex align-items-center mb-4" role="alert" style="border-left: 5px solid">
            <div style="font-size: 2.5rem; margin-right: 20px;">🤖</div>
            <div style="width: 100%;">
                <h4 class="alert-heading mb-1">AI Smart LMSX: Analisis Performa</h4>
                <p class="mb-1">Tingkat risiko kegagalan akademik Anda saat ini: <strong>'.$score.'% ('.$recommendation.')</strong></p>';

        if ($rec) {
            echo '
                <hr class="my-2">
                <p class="mb-1">📖 <strong>Rekomendasi Materi Untuk Anda:</strong></p>
                <div class="p-2 bg-white rounded shadow-sm text-dark mb-2">
                    <strong>'.s($rec->material_name).'</strong><br>
                    <small class="text-muted"><i>Reason: '.s($rec->reason).'</i></small>
                </div>';
        }

        $student_analysis_html = "";

        if (!empty($record->risk_breakdown)) {
            $breakdown = json_decode($record->risk_breakdown, true);
            
            if (is_array($breakdown)) {
                arsort($breakdown);

                $xai_bg = '#d4edda'; $xai_border = '#28a745'; $xai_text = '#155724'; // Default: Hijau (Aman)
                
                if ($class == 'danger') {
                    $xai_bg = '#e9b4b8'; $xai_border = '#dc3545'; $xai_text = 'rgb(145, 19, 32)'; // Merah (Risiko Tinggi)
                } else if ($class == 'warning') {
                    $xai_bg = '#fff3cd'; $xai_border = '#ffc107'; $xai_text = '#856404'; // Kuning (Risiko Sedang)
                }

                $student_analysis_html .= "<div class='student-xai-box mt-3' style='font-size: 0.9em; background-color: {$xai_bg}; padding: 12px; border-radius: 6px; border-left: 4px solid {$xai_border}; color: {$xai_text};'>";
                $student_analysis_html .= "<strong><i class='fa fa-info-circle'></i> Rekomendasi Evaluasi Mandiri:</strong>";
                $student_analysis_html .= "<span class='d-block mb-2' style='font-size:0.95em; opacity: 0.9;'>Sistem mendeteksi beberapa aspek perilaku belajar Anda yang paling berpengaruh:</span>";
                $student_analysis_html .= "<ul class='list-unstyled mb-2'>";
                
                $count = 0;
                $perlu_investigasi = false;
                
                foreach ($breakdown as $faktor => $nilai_shap) {
                    
                    $persentase_shap = round($nilai_shap * 100, 1);
                    
                    if ($persentase_shap > 1.0) {
                        $nama_faktor_mahasiswa = $faktor;
                        $tambahan_info = ""; 
                        
                        if ($faktor == 'Total Interaksi Log') {
                            $nama_faktor_mahasiswa = 'Tingkat keaktifan dalam course';
                        }
                        if ($faktor == 'Rata-rata Keterlambatan Tugas') {
                            $nama_faktor_mahasiswa = 'Keterlambatan pengumpulan tugas';
                        }
                        
                        $faktor_lower = strtolower($faktor);

                        if (strpos($faktor_lower, 'interaksi h5p') !== false) {
                            $user_h5p_int = $DB->get_field_sql("
                                SELECT COUNT(hxr.id) 
                                FROM {hvp_xapi_results} hxr
                                JOIN {hvp} h ON hxr.content_id = h.id
                                WHERE hxr.user_id = ? AND h.course = ?
                            ", array($USER->id, $courseid));
                            
                            $class_h5p_int_total = $DB->get_field_sql("
                                SELECT COUNT(hxr.id) 
                                FROM {hvp_xapi_results} hxr
                                JOIN {hvp} h ON hxr.content_id = h.id
                                WHERE h.course = ?
                            ", array($courseid));
                            
                            $class_h5p_users = $DB->get_field_sql("
                                SELECT COUNT(DISTINCT hxr.user_id) 
                                FROM {hvp_xapi_results} hxr
                                JOIN {hvp} h ON hxr.content_id = h.id
                                WHERE h.course = ?
                            ", array($courseid));
                            
                            $class_h5p_int = ($class_h5p_users > 0) ? round($class_h5p_int_total / $class_h5p_users, 1) : 0;
                            
                            $current_pct = ($class_h5p_int > 0) ? round(($user_h5p_int / $class_h5p_int) * 100, 0) : 100;
                            if ($current_pct > 100) $current_pct = 100; 
                            
                            $target_count = ceil($class_h5p_int * 0.75);
                            $needed_count = $target_count - $user_h5p_int;
                            
                            if ($needed_count > 0) {
                                $tambahan_info = " (Saat ini: <strong>{$current_pct}%</strong>, Minimal kontribusi 75%, <strong>{$needed_count} kali</strong> hingga mencapai batas minimal)";
                            } else {
                                $tambahan_info = " (Saat ini: <strong>{$current_pct}%</strong>, Anda telah mencapai batas minimal)";
                            }
                        }

                        else if (strpos($faktor_lower, 'nilai') !== false) {

                            $actual_pct = $DB->get_field_sql("
                                SELECT COALESCE((SUM(gg.finalgrade) / NULLIF(SUM(gi.grademax), 0)) * 100, 0) 
                                FROM {grade_grades} gg
                                JOIN {grade_items} gi ON gg.itemid = gi.id
                                WHERE gg.userid = ? AND gi.courseid = ? AND gi.itemtype = 'mod'
                            ", array($USER->id, $courseid));
                            
                            $actual_pct = round($actual_pct, 1);
                            $tambahan_info = " (Saat ini rasio nilai Anda: <strong>{$actual_pct}%</strong> dari batas maksimal)";
                        }

                        else if (strpos($faktor_lower, 'forum') !== false) {

                            $user_posts = $DB->get_field_sql("SELECT COUNT(p.id) FROM {forum_posts} p JOIN {forum_discussions} d ON p.discussion = d.id WHERE p.userid = ? AND d.course = ?", array($USER->id, $courseid));
                            $user_disc = $DB->get_field_sql("SELECT COUNT(id) FROM {forum_discussions} WHERE userid = ? AND course = ?", array($USER->id, $courseid));
                            $user_forum_count = $user_posts + $user_disc;

                            $class_posts = $DB->get_field_sql("SELECT COUNT(p.id) FROM {forum_posts} p JOIN {forum_discussions} d ON p.discussion = d.id WHERE d.course = ?", array($courseid));
                            $class_disc = $DB->get_field_sql("SELECT COUNT(id) FROM {forum_discussions} WHERE course = ?", array($courseid));
                            $total_class_forum = $class_posts + $class_disc;

                            $total_class_users = $DB->get_field_sql("
                                SELECT COUNT(DISTINCT userid) FROM (
                                    SELECT p.userid FROM {forum_posts} p JOIN {forum_discussions} d ON p.discussion = d.id WHERE d.course = ?
                                    UNION
                                    SELECT userid FROM {forum_discussions} WHERE course = ?
                                ) as combined_users
                            ", array($courseid, $courseid));

                            $class_forum_avg = ($total_class_users > 0) ? round($total_class_forum / $total_class_users, 1) : 0;
                            
                            $current_pct = ($class_forum_avg > 0) ? round(($user_forum_count / $class_forum_avg) * 100, 0) : 100;
                            if ($current_pct > 100) $current_pct = 100; 
                            
                            $target_count = ceil($class_forum_avg * 0.75); 
                            $needed_count = $target_count - $user_forum_count;
                            
                            if ($needed_count > 0) {
                                $tambahan_info = " (Saat ini: <strong>{$current_pct}%</strong>, Minimal kontribusi 75%, <strong>{$needed_count} aktivitas</strong> hingga mencapai batas minimal)";
                            } else {
                                $tambahan_info = " (Saat ini: <strong>{$current_pct}%</strong>, Anda telah mencapai batas minimal)";
                            }
                        }

                        $student_analysis_html .= "<li><i class='fa fa-arrow-up'></i> <strong>{$nama_faktor_mahasiswa}</strong> memengaruhi peningkatan risiko akademik Anda{$tambahan_info}.</li>";
                        
                        if (strpos($faktor_lower, 'tugas') !== false || 
                            strpos($faktor_lower, 'kuis') !== false || 
                            strpos($faktor_lower, 'nilai') !== false ||
                            strpos($faktor_lower, 'rasio') !== false ||
                            strpos($faktor_lower, 'forum') !== false) {
                            $perlu_investigasi = true;
                        }
                        
                        $count++;
                    }
                }
                $student_analysis_html .= "</ul>";



                    $sql_missing_tasks = "SELECT a.id, a.name as nama_aktivitas, cs.section as minggu_ke, 
                                          (GREATEST(COALESCE(gi.aggregationcoef2, 0), COALESCE(gi.aggregationcoef, 0)) * 100) AS bobot_persen
                                          FROM {assign} a
                                          JOIN {course_modules} cm ON a.id = cm.instance
                                          JOIN {modules} m ON cm.module = m.id
                                          JOIN {course_sections} cs ON cm.section = cs.id
                                          LEFT JOIN {assign_submission} asb ON a.id = asb.assignment AND asb.userid = :userid
                                          LEFT JOIN {grade_items} gi ON gi.iteminstance = a.id AND gi.itemmodule = 'assign' AND gi.courseid = a.course AND gi.itemtype = 'mod'
                                          WHERE a.course = :courseid 
                                            AND m.name = 'assign'
                                            AND (asb.status IS NULL OR asb.status = 'new' OR asb.status = 'draft')
                                          ORDER BY cs.section ASC";
                    $missing_tasks = $DB->get_records_sql($sql_missing_tasks, ['userid' => $USER->id, 'courseid' => $courseid]);

                    $sql_missing_quizzes = "SELECT q.id, q.name as nama_aktivitas, cs.section as minggu_ke,
                                            (GREATEST(COALESCE(gi.aggregationcoef2, 0), COALESCE(gi.aggregationcoef, 0)) * 100) AS bobot_persen
                                            FROM {quiz} q
                                            JOIN {course_modules} cm ON q.id = cm.instance
                                            JOIN {modules} m ON cm.module = m.id
                                            JOIN {course_sections} cs ON cm.section = cs.id
                                            LEFT JOIN {quiz_attempts} qa ON q.id = qa.quiz AND qa.userid = :userid
                                            LEFT JOIN {grade_items} gi ON gi.iteminstance = q.id AND gi.itemmodule = 'quiz' AND gi.courseid = q.course AND gi.itemtype = 'mod'
                                            WHERE q.course = :courseid 
                                              AND m.name = 'quiz'
                                              AND qa.id IS NULL
                                            ORDER BY cs.section ASC";
                    $missing_quizzes = $DB->get_records_sql($sql_missing_quizzes, ['userid' => $USER->id, 'courseid' => $courseid]);

                    $sql_missing_forums = "SELECT f.id, f.name as nama_aktivitas, cs.section as minggu_ke,
                                           (GREATEST(COALESCE(gi.aggregationcoef2, 0), COALESCE(gi.aggregationcoef, 0)) * 100) AS bobot_persen
                                           FROM {forum} f
                                           JOIN {course_modules} cm ON f.id = cm.instance
                                           JOIN {modules} m ON cm.module = m.id
                                           JOIN {course_sections} cs ON cm.section = cs.id
                                           LEFT JOIN {grade_items} gi ON gi.iteminstance = f.id AND gi.itemmodule = 'forum' AND gi.courseid = f.course AND gi.itemtype = 'mod'
                                           WHERE f.course = :courseid
                                             AND m.name = 'forum'
                                             AND NOT EXISTS (
                                                 SELECT 1 FROM {forum_posts} fp
                                                 JOIN {forum_discussions} fd ON fp.discussion = fd.id
                                                 WHERE fd.forum = f.id AND fp.userid = :userid
                                             )
                                           ORDER BY cs.section ASC";
                    $missing_forums = $DB->get_records_sql($sql_missing_forums, ['userid' => $USER->id, 'courseid' => $courseid]);

                    $sql_missing_h5ps = "SELECT h.id, h.name as nama_aktivitas, cs.section as minggu_ke,
                                         (GREATEST(COALESCE(gi.aggregationcoef2, 0), COALESCE(gi.aggregationcoef, 0)) * 100) AS bobot_persen
                                         FROM {hvp} h
                                         JOIN {course_modules} cm ON h.id = cm.instance
                                         JOIN {modules} m ON cm.module = m.id
                                         JOIN {course_sections} cs ON cm.section = cs.id
                                         LEFT JOIN {grade_items} gi ON gi.iteminstance = h.id AND gi.itemmodule = 'hvp' AND gi.courseid = h.course AND gi.itemtype = 'mod'
                                         WHERE h.course = :courseid
                                           AND m.name = 'hvp'
                                           AND NOT EXISTS (
                                               SELECT 1 FROM {hvp_xapi_results} hxr 
                                               WHERE hxr.content_id = h.id AND hxr.user_id = :userid
                                           )
                                         ORDER BY cs.section ASC";
                    $missing_h5ps = $DB->get_records_sql($sql_missing_h5ps, ['userid' => $USER->id, 'courseid' => $courseid]);

                    if ($missing_tasks || $missing_quizzes || $missing_forums || $missing_h5ps) {
                        $student_analysis_html .= "<div class='missing-activities mt-2 pt-2' style='border-top: 1px dashed rgba(0,0,0,0.1);'>";
                        $student_analysis_html .= "<strong class='d-block mb-1' style='opacity: 0.9;'><i class='fa fa-tasks'></i> Aktivitas Kuliah yang Belum Selesai:</strong>";
                        $student_analysis_html .= "<ul style='margin-bottom: 0; padding-left: 20px;'>";

                        if ($missing_tasks) {
                            foreach ($missing_tasks as $task) {
                                $label = ($task->minggu_ke > 0) ? "Topik " . $task->minggu_ke : "Pendahuluan";
                                $info_bobot = (isset($task->bobot_persen) && $task->bobot_persen > 0) ? " <strong class='text-danger' style='font-size: 0.9em;'>(Bobot: ".round($task->bobot_persen, 1)."%)</strong>" : "";
                                $student_analysis_html .= "<li>{$task->nama_aktivitas} <span class='badge badge-primary'>Tugas</span><small style='opacity: 0.8;'>({$label})</small> {$info_bobot}</li>";
                            }
                        }

                        if ($missing_quizzes) {
                            foreach ($missing_quizzes as $quiz) {
                                $label = ($quiz->minggu_ke > 0) ? "Topik " . $quiz->minggu_ke : "Pendahuluan";
                                $info_bobot = (isset($quiz->bobot_persen) && $quiz->bobot_persen > 0) ? " <strong class='text-danger' style='font-size: 0.9em;'>(Bobot: ".round($quiz->bobot_persen, 1)."%)</strong>" : "";
                                $student_analysis_html .= "<li>{$quiz->nama_aktivitas} <span class='badge badge-warning'>Kuis</span><small style='opacity: 0.8;'>({$label})</small> {$info_bobot}</li>";
                            }
                        }

                        if ($missing_forums) {
                            foreach ($missing_forums as $forum) {
                                $label = ($forum->minggu_ke > 0) ? "Topik " . $forum->minggu_ke : "Pendahuluan";
                                $info_bobot = (isset($forum->bobot_persen) && $forum->bobot_persen > 0) ? " <strong class='text-danger' style='font-size: 0.9em;'>(Bobot: ".round($forum->bobot_persen, 1)."%)</strong>" : "";
                                $student_analysis_html .= "<li>{$forum->nama_aktivitas} <span class='badge badge-info'>Forum</span><small style='opacity: 0.8;'>({$label})</small> {$info_bobot}</li>";
                            }
                        }

                        if ($missing_h5ps) {
                            foreach ($missing_h5ps as $h5p) {
                                $label = ($h5p->minggu_ke > 0) ? "Topik " . $h5p->minggu_ke : "Pendahuluan";
                                $info_bobot = (isset($h5p->bobot_persen) && $h5p->bobot_persen > 0) ? " <strong class='text-danger' style='font-size: 0.9em;'>(Bobot: ".round($h5p->bobot_persen, 1)."%)</strong>" : "";
                                $student_analysis_html .= "<li>{$h5p->nama_aktivitas} <span class='badge badge-success'>H5P</span><small style='opacity: 0.8;'>({$label})</small> {$info_bobot}</li>";
                            }
                        }
                        
                        $student_analysis_html .= "</ul>";
                        $student_analysis_html .= "<span class='d-block mt-2' style='font-size:0.9em;'>💡 <em>Segera selesaikan aktivitas di atas untuk memperbaiki performa belajar Anda.</em></span>";
                        $student_analysis_html .= "</div>";
                    }
                
                
                $student_analysis_html .= "</div>"; 
            }
        }        
        echo $student_analysis_html;

        echo '
            </div>
        </div>';
    }
}