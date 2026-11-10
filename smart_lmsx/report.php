<?php
require_once(__DIR__ . '/../../config.php');
require_login();

$courseid = required_param('id', PARAM_INT);
$context = context_course::instance($courseid);
require_capability('moodle/course:manageactivities', $context);

$PAGE->set_url(new moodle_url('/local/smart_lmsx/report.php', array('id' => $courseid)));
$PAGE->set_context($context);
$PAGE->set_title('Smart LMSX: Daftar Mahasiswa Berisiko');
$PAGE->set_heading('Dasbor Analitik Risiko Mahasiswa');

echo $OUTPUT->header();
echo '<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>';

$sql = "SELECT u.id, u.firstname, u.lastname, p.risk_score, p.timemodified, p.risk_breakdown
        FROM {user} u
        JOIN {local_smart_lmsx_pred} p ON u.id = p.userid
        JOIN {role_assignments} ra ON ra.userid = u.id
        JOIN {context} ctx ON ctx.id = ra.contextid
        WHERE p.courseid = :courseid
          AND ctx.instanceid = :courseid2
          AND ra.roleid = 5
        ORDER BY p.risk_score DESC";

$predictions = $DB->get_records_sql($sql, [
    'courseid' => $courseid, 
    'courseid2' => $courseid
]);

if ($predictions) {
    $total_students = 0;
    $high_risk_count = 0;
    $medium_risk_count = 0;
    $low_risk_count = 0;
    $sum_risk_score = 0;
    $global_factors = [];

    foreach ($predictions as $pred) {
        $total_students++;
        $score = round($pred->risk_score * 100, 1);
        $sum_risk_score += $score;
        
        if ($score > 70) {
            $high_risk_count++;
        } else if ($score > 35) {
            $medium_risk_count++;
        } else {
            $low_risk_count++;
        }

        if (!empty($pred->risk_breakdown)) {
            $breakdown = json_decode($pred->risk_breakdown, true);
            if (is_array($breakdown)) {
                foreach ($breakdown as $faktor => $nilai_shap) {
                    if (round($nilai_shap * 100, 1) > 1.0) {
                        if (!isset($global_factors[$faktor])) {
                            $global_factors[$faktor] = 0;
                        }
                        $global_factors[$faktor]++; 
                    }
                }
            }
        }
    }
    
    $avg_risk = ($total_students > 0) ? round($sum_risk_score / $total_students, 1) : 0;
    arsort($global_factors);
    
    $factor_labels = array_keys($global_factors);
    $factor_counts = array_values($global_factors);

    echo '
    <div class="macro-dashboard mb-4">
        <h4 class="mb-3 text-muted"><i class="fa fa-bar-chart"></i> Tinjauan Keseluruhan Kelas</h4>
        
        <div class="row text-center mb-4">
            <div class="col-md">
                <div class="card shadow-sm border-0" style="border-left: 4px solid #007bff !important;">
                    <div class="card-body py-3">
                        <div class="small font-weight-bold text-uppercase mb-1" style="color: #007bff;">Total Mahasiswa</div>
                        <div class="h3 mb-0 font-weight-bold text-dark">'.$total_students.'</div>
                    </div>
                </div>
            </div>
            
            <div class="col-md">
                <div class="card shadow-sm border-0" style="border-left: 4px solid #dc3545 !important;">
                    <div class="card-body py-3">
                        <div class="small font-weight-bold text-uppercase mb-1 text-danger">Berisiko Tinggi</div>
                        <div class="h3 mb-0 font-weight-bold text-danger">'.$high_risk_count.'</div>
                    </div>
                </div>
            </div>
            
            <div class="col-md">
                <div class="card shadow-sm border-0" style="border-left: 4px solid #ffc107 !important;">
                    <div class="card-body py-3">
                        <div class="small font-weight-bold text-uppercase mb-1" style="color: #d39e00;">Risiko Sedang</div>
                        <div class="h3 mb-0 font-weight-bold" style="color: #d39e00;">'.$medium_risk_count.'</div>
                    </div>
                </div>
            </div>

            <div class="col-md">
                <div class="card shadow-sm border-0" style="border-left: 4px solid #28a745 !important;">
                    <div class="card-body py-3">
                        <div class="small font-weight-bold text-uppercase mb-1 text-success">Status Aman</div>
                        <div class="h3 mb-0 font-weight-bold text-success">'.$low_risk_count.'</div>
                    </div>
                </div>
            </div>
            
            <div class="col-md">
                <div class="card shadow-sm border-0" style="border-left: 4px solid #17a2b8 !important;">
                    <div class="card-body py-3">
                        <div class="small font-weight-bold text-uppercase mb-1 text-info">Rata-rata Kelas</div>
                        <div class="h3 mb-0 font-weight-bold text-info">'.$avg_risk.'%</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-4 mb-3">
                <div class="card shadow-sm border-0 h-100">
                    <div class="card-header bg-white border-0 font-weight-bold text-muted">Distribusi Tingkat Risiko</div>
                    <div class="card-body d-flex align-items-center justify-content-center">
                        <div style="height: 350px; width: 100%;">
                            <canvas id="macroDoughnutChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-8 mb-3">
                <div class="card shadow-sm border-0 h-100">
                    <div class="card-header bg-white border-0 font-weight-bold text-muted">Faktor Pemicu Risiko Terbanyak di Kelas Ini</div>
                    <div class="card-body">
                        <div style="height: 350px; width: 100%;">
                            <canvas id="macroBarChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <hr class="mb-4" style="border-top: 2px solid #eee;">
    <h4 class="mb-3 text-muted"><i class="fa fa-users"></i> Rincian Individu Mahasiswa</h4>';

    // Render tabel individu
    echo '<table class="generaltable" style="width:100%">
            <thead>
                <tr>
                    <th>Nama Mahasiswa</th>
                    <th>Tingkat Risiko</th>
                    <th>Status & Analisis Faktor (XAI)</th>
                    <th>Update Terakhir</th>
                </tr>
            </thead>
            <tbody>';

    $chart_js_data = [];

    foreach ($predictions as $pred) {
        $fullname = fullname($pred);
        $percentage = round($pred->risk_score * 100, 2);
        
        $status_badge = ($pred->risk_score > 0.5) 
            ? '<span class="badge badge-danger" style="font-size:14px;">Berisiko</span>' 
            : '<span class="badge badge-success" style="font-size:14px;">Aman</span>';

        $breakdown_html = "";
        
        if (isset($pred->risk_breakdown) && !empty($pred->risk_breakdown)) {
            $breakdown = json_decode($pred->risk_breakdown, true);
            
            if (is_array($breakdown)) {
                arsort($breakdown);

                $chart_js_data[$pred->id] = [
                    'labels' => array_keys($breakdown),
                    'values' => array_map(function($v) { return round($v * 100, 1); }, array_values($breakdown))
                ];

                $breakdown_html .= "<div class='mt-2' style='font-size: 0.85em; background-color: #f8f9fa; padding: 10px; border-radius: 5px; border-left: 3px solid #dc3545;'>";
                $breakdown_html .= "<div class='d-flex justify-content-between align-items-center mb-1'>";
                $breakdown_html .= "<strong class='text-muted'>Faktor Pemicu Risiko:</strong>";
                $breakdown_html .= "<button class='btn btn-xs btn-outline-primary' type='button' data-toggle='collapse' data-target='#chart-container-{$pred->id}' aria-expanded='false' style='font-size: 11px; padding: 1px 6px;'><i class='fa fa-line-chart'></i> Lihat Grafik Individu</button>";
                $breakdown_html .= "</div>";

                $breakdown_html .= "<div class='collapse mb-2' id='chart-container-{$pred->id}'>";
                $breakdown_html .= "  <div class='bg-white p-2 border rounded mt-1'>";
                $breakdown_html .= "    <canvas id='canvas-{$pred->id}' style='max-height: 180px; width: 100%;'></canvas>";
                $breakdown_html .= "  </div>";
                $breakdown_html .= "</div>";

                $breakdown_html .= "<ul class='list-unstyled mb-1'>";
                
                foreach ($breakdown as $faktor => $nilai_shap) {
                    $persentase_shap = round($nilai_shap * 100, 1);
                    if ($persentase_shap > 0) {
                        $breakdown_html .= "<li><span class='text-danger font-weight-bold'>+{$persentase_shap}%</span> {$faktor}</li>";
                    }
                }
                $breakdown_html .= "</ul>";

                    $sql_tasks = "SELECT a.id, a.name, cs.section FROM {assign} a JOIN {course_modules} cm ON a.id = cm.instance JOIN {modules} m ON cm.module = m.id JOIN {course_sections} cs ON cm.section = cs.id LEFT JOIN {assign_submission} asb ON a.id = asb.assignment AND asb.userid = :userid WHERE a.course = :courseid AND m.name = 'assign' AND (asb.status IS NULL OR asb.status = 'new' OR asb.status = 'draft') ORDER BY cs.section ASC";
                    $m_tasks = $DB->get_records_sql($sql_tasks, ['userid' => $pred->id, 'courseid' => $courseid]);

                    $sql_quizzes = "SELECT q.id, q.name, cs.section FROM {quiz} q JOIN {course_modules} cm ON q.id = cm.instance JOIN {modules} m ON cm.module = m.id JOIN {course_sections} cs ON cm.section = cs.id LEFT JOIN {quiz_attempts} qa ON q.id = qa.quiz AND qa.userid = :userid WHERE q.course = :courseid AND m.name = 'quiz' AND qa.id IS NULL ORDER BY cs.section ASC";
                    $m_quizzes = $DB->get_records_sql($sql_quizzes, ['userid' => $pred->id, 'courseid' => $courseid]);

                    $sql_forums = "SELECT f.id, f.name, cs.section FROM {forum} f JOIN {course_modules} cm ON f.id = cm.instance JOIN {modules} m ON cm.module = m.id JOIN {course_sections} cs ON cm.section = cs.id WHERE f.course = :courseid AND m.name = 'forum' AND NOT EXISTS (SELECT 1 FROM {forum_posts} fp JOIN {forum_discussions} fd ON fp.discussion = fd.id WHERE fd.forum = f.id AND fp.userid = :userid) ORDER BY cs.section ASC";
                    $m_forums = $DB->get_records_sql($sql_forums, ['userid' => $pred->id, 'courseid' => $courseid]);

                    $sql_h5p = "SELECT h.id, h.name, cs.section FROM {hvp} h JOIN {course_modules} cm ON h.id = cm.instance JOIN {modules} m ON cm.module = m.id JOIN {course_sections} cs ON cm.section = cs.id WHERE h.course = :courseid AND m.name = 'hvp' AND NOT EXISTS (SELECT 1 FROM {hvp_xapi_results} hxr WHERE hxr.content_id = h.id AND hxr.user_id = :userid) ORDER BY cs.section ASC";
                    $m_h5ps = $DB->get_records_sql($sql_h5p, ['userid' => $pred->id, 'courseid' => $courseid]);

                    if ($m_tasks || $m_quizzes || $m_forums || $m_h5ps) {
                        $breakdown_html .= "<div class='mt-2 pt-2' style='border-top: 1px dashed #ccc;'>";
                        $breakdown_html .= "<strong class='text-danger d-block mb-1'><i class='fa fa-exclamation-circle'></i> Target Intervensi Dosen:</strong>";
                        $breakdown_html .= "<ul style='margin-bottom: 0; padding-left: 20px;'>";
                        
                        if ($m_tasks) foreach ($m_tasks as $t) $breakdown_html .= "<li>{$t->name} <span class='badge badge-primary'>Tugas</span></li>";
                        if ($m_quizzes) foreach ($m_quizzes as $q) $breakdown_html .= "<li>{$q->name} <span class='badge badge-warning'>Kuis</span></li>";
                        if ($m_forums) foreach ($m_forums as $f) $breakdown_html .= "<li>{$f->name} <span class='badge badge-info'>Forum</span></li>";
                        if ($m_h5ps) foreach ($m_h5ps as $h) $breakdown_html .= "<li>{$h->name} <span class='badge badge-success'>H5P</span></li>";
                        
                        $breakdown_html .= "</ul></div>";
                    }
                
                $breakdown_html .= "</div>";
            }
        }

        echo "<tr>
                <td class='align-middle font-weight-bold'>{$fullname}</td>
                <td class='align-middle text-center' style='font-size: 1.2em; font-weight: bold;'>{$percentage}%</td>
                <td>{$status_badge} {$breakdown_html}</td>
                <td class='align-middle text-muted' style='font-size: 0.85em;'>" . userdate($pred->timemodified) . "</td>
              </tr>";
    }
    echo '</tbody></table>';

    ?>
    <script>
    document.addEventListener("DOMContentLoaded", function() {
        var ctxDoughnut = document.getElementById('macroDoughnutChart').getContext('2d');
        new Chart(ctxDoughnut, {
            type: 'doughnut',
            data: {
                labels: ['Aman (0-35%)', 'Sedang (36-70%)', 'Tinggi (>70%)'],
                datasets: [{
                    data: [<?php echo $low_risk_count; ?>, <?php echo $medium_risk_count; ?>, <?php echo $high_risk_count; ?>],
                    backgroundColor: ['#28a745', '#ffc107', '#dc3545'],
                    borderWidth: 1,
                    borderColor: '#ffffff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom', labels: { boxWidth: 12 } } },
                cutout: '65%'
            }
        });

        var ctxBar = document.getElementById('macroBarChart').getContext('2d');
        new Chart(ctxBar, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($factor_labels); ?>,
                datasets: [{
                    label: 'Jumlah Mahasiswa Terdampak',
                    data: <?php echo json_encode($factor_counts); ?>,
                    backgroundColor: 'rgba(54, 162, 235, 0.7)',
                    borderColor: '#36a2eb',
                    borderWidth: 1,
                    borderRadius: 4
                }]
            },
            options: {
                indexAxis: 'y', 
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { beginAtZero: true, ticks: { stepSize: 1 } },
                    y: { ticks: { font: { size: 11 } } }
                }
            }
        });

        var chartData = <?php echo json_encode($chart_js_data); ?>;
        Object.keys(chartData).forEach(function(userId) {
            var ctx = document.getElementById('canvas-' + userId).getContext('2d');
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: chartData[userId].labels,
                    datasets: [{
                        label: 'Kontribusi Nilai Risiko (+%)',
                        data: chartData[userId].values,
                        borderColor: '#dc3545',
                        backgroundColor: 'rgba(220, 53, 69, 0.1)',
                        borderWidth: 2,
                        pointBackgroundColor: '#dc3545',
                        tension: 0.3,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        y: { beginAtZero: true, ticks: { callback: function(value) { return '+' + value + '%'; } } },
                        x: { ticks: { font: { size: 9 } } }
                    }
                }
            });
        });
    });
    </script>
    <?php
} else {
    echo $OUTPUT->notification('Belum ada data prediksi untuk mata kuliah ini.', 'info');
}

echo $OUTPUT->footer();