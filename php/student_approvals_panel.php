<?php /* Approvals tab body - included by users.php; see student_approvals_logic.php */ ?>
    <?php if (!$tablesReady): ?>
        <div class="flash-error">The class-list table is missing. Run <code>database/migration_28_registration_approval.sql</code> first.</div>
    <?php else: ?>

    <div class="card">
        <strong>LRN class list:</strong>
        <?php if ($rosterCount > 0): ?>
            <?= number_format($rosterCount) ?> LRNs loaded. Students whose LRN is on the list are activated automatically; everyone else waits below for approval.
        <?php else: ?>
            none uploaded. Registration is open: every new account is active immediately.
        <?php endif; ?>

        <p style="margin:12px 0 0;">
            <strong>Not sure about the format?</strong> Download a ready-made template, replace the sample rows with your students, and upload it:
            <a href="download_roster_template.php?type=xlsx" style="color:#6e1423;font-weight:bold;">Excel template (.xlsx)</a>
            &nbsp;|&nbsp;
            <a href="download_roster_template.php?type=csv" style="color:#6e1423;font-weight:bold;">CSV template (.csv)</a>
            <br><span class="muted">Delete the two sample rows (Juan Dela Cruz, Maria Santos) before uploading. The Excel template already has the LRN column set to Text, so no digits get cut off.</span>
        </p>

        <form method="POST" action="users.php#approvals" enctype="multipart/form-data" style="margin-top:14px;">
            <input type="hidden" name="action" value="upload_roster">
            <input type="file" name="roster_file" accept=".csv,.xlsx" required>
            <label class="muted" style="display:inline-block;margin-left:10px;">
                <input type="checkbox" name="replace_list" value="1"> Replace the existing list
            </label>
            <button type="submit" class="btn-primary" style="margin-left:10px;">Upload list</button>
            <p class="muted" style="margin:10px 0 0;">
                CSV or Excel (.xlsx). Put the 12-digit LRN in the first column and the student's full name in the second. When a name is listed, a new account is approved automatically only if the LRN <strong>and</strong> the name match (capitals, punctuation and word order are ignored); otherwise it waits for approval. Format the LRN column as <strong>Text</strong> so Excel does not shorten it. A header row is skipped automatically.
            </p>
        </form>

        <?php if ($rosterCount > 0): ?>
            <form method="POST" action="users.php#approvals" class="inline" onsubmit="return confirm('Clear the whole class list? New registrations will be active immediately again.');">
                <input type="hidden" name="action" value="clear_roster">
                <button type="submit" class="btn-secondary" style="margin-top:12px;">Clear class list</button>
            </form>
        <?php endif; ?>
    </div>

    <h3 style="color:#6e1423;margin-top:26px;">Waiting for approval (<?= count($pending) ?>)</h3>

    <?php if (!$pending): ?>
        <p class="empty">No accounts are waiting for approval.</p>
    <?php else: ?>
        <form method="POST" action="users.php#approvals" id="bulkForm">
            <table>
                <thead>
                    <tr>
                        <th><input type="checkbox" id="selectAll" aria-label="Select all"></th>
                        <th>LRN</th><th>Name typed</th><th>Name on class list</th><th>Email</th><th>Grade level</th><th>Age</th><th>Registered</th><th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($pending as $p): ?>
                    <tr>
                        <td><input type="checkbox" name="ids[]" value="<?= (int) $p['student_id'] ?>" class="rowCheck"></td>
                        <td><?= htmlspecialchars($p['student_number']) ?></td>
                        <td><?= htmlspecialchars($p['name']) ?></td>
                        <td><?= $p['on_list'] ? htmlspecialchars($p['roster_name'] ?: '(no name listed)') : '<span style="color:#b02a37;">LRN not on list</span>' ?></td>
                        <td><?= htmlspecialchars($p['email']) ?></td>
                        <td><?= htmlspecialchars($p['grade_level'] ?? '—') ?></td>
                        <td><?= $p['age'] !== null ? (int) $p['age'] : '—' ?></td>
                        <td><?= htmlspecialchars(date('M j, Y g:i A', strtotime($p['created_at']))) ?></td>
                        <td>
                            <button type="submit" name="action" value="approve" class="btn-primary"
                                    onclick="this.form.querySelectorAll('.single').forEach(e=>e.remove());var h=document.createElement('input');h.type='hidden';h.name='student_id';h.value='<?= (int) $p['student_id'] ?>';h.className='single';this.form.appendChild(h);">Approve</button>
                            <button type="submit" name="action" value="reject" class="btn-danger"
                                    onclick="var r=prompt('Reject this registration? The student can register again.\nReason (optional):','');if(r===null){return false;}this.form.querySelectorAll('.single').forEach(e=>e.remove());var h=document.createElement('input');h.type='hidden';h.name='student_id';h.value='<?= (int) $p['student_id'] ?>';h.className='single';this.form.appendChild(h);var g=document.createElement('input');g.type='hidden';g.name='reason';g.value=r;g.className='single';this.form.appendChild(g);">Reject</button>
                        </td>
                    </tr>
                    <?php $pid = (int) $p['student_id']; ?>
                    <tr>
                        <td></td>
                        <td colspan="8">
                            <details>
                                <summary>Edit details (after confirming this is really your student)</summary>
                                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:10px;margin:10px 0;">
                                    <input type="hidden" name="student_id" value="<?= $pid ?>" form="editForm<?= $pid ?>">
                                    <input type="hidden" name="action" value="edit_pending" form="editForm<?= $pid ?>">
                                    <label>Name<br><input type="text" name="name" value="<?= htmlspecialchars($p['name']) ?>" required form="editForm<?= $pid ?>" style="width:100%;box-sizing:border-box;"></label>
                                    <label>LRN (12 digits)<br><input type="text" name="student_number" value="<?= htmlspecialchars($p['student_number']) ?>" required pattern="[0-9]{12}" maxlength="12" inputmode="numeric" form="editForm<?= $pid ?>" style="width:100%;box-sizing:border-box;"></label>
                                    <label>Email<br><input type="email" name="email" value="<?= htmlspecialchars($p['email']) ?>" required form="editForm<?= $pid ?>" style="width:100%;box-sizing:border-box;"></label>
                                    <label>Grade level<br>
                                        <select name="grade_level" required form="editForm<?= $pid ?>" style="width:100%;box-sizing:border-box;">
                                            <?php if ($p['grade_level'] !== null && !in_array($p['grade_level'], $gradeLevelOptions, true)): ?>
                                                <option value="<?= htmlspecialchars($p['grade_level']) ?>" selected><?= htmlspecialchars($p['grade_level']) ?></option>
                                            <?php endif; ?>
                                            <?php foreach ($gradeLevelOptions as $opt): ?>
                                                <option value="<?= htmlspecialchars($opt) ?>" <?= $p['grade_level'] === $opt ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                    <label>Age<br><input type="number" name="age" min="10" max="25" value="<?= $p['age'] !== null ? (int) $p['age'] : '' ?>" required form="editForm<?= $pid ?>" style="width:100%;box-sizing:border-box;"></label>
                                </div>
                                <button type="submit" class="btn-secondary" form="editForm<?= $pid ?>">Save details</button>
                            </details>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <div style="margin-top:14px;">
                <button type="submit" name="action" value="approve_selected" class="btn-primary">Approve selected</button>
                <button type="submit" name="action" value="approve_all" class="btn-secondary"
                        onclick="return confirm('Approve ALL <?= count($pending) ?> waiting accounts?');">Approve all</button>
            </div>
        </form>
        <?php foreach ($pending as $p): ?>
            <form id="editForm<?= (int) $p['student_id'] ?>" method="POST" action="users.php#approvals"></form>
        <?php endforeach; ?>
        <script>
            document.getElementById('selectAll').addEventListener('change', function () {
                document.querySelectorAll('.rowCheck').forEach(function (c) { c.checked = this.checked; }, this);
            });
        </script>
    <?php endif; ?>

    <h3 style="color:#6e1423;margin-top:34px;">Rejected (<?= count($rejected) ?>)</h3>
    <?php if (!$rejected): ?>
        <p class="empty">No rejected registrations. Rejected accounts are listed here, and the student can register again.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr><th>LRN</th><th>Name</th><th>Email</th><th>Grade level</th><th>Rejected</th><th>By</th><th>Reason</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($rejected as $r): ?>
                <tr>
                    <td><?= htmlspecialchars($r['student_number']) ?></td>
                    <td><?= htmlspecialchars($r['name']) ?></td>
                    <td><?= htmlspecialchars($r['email']) ?></td>
                    <td><?= htmlspecialchars($r['grade_level'] ?? '—') ?></td>
                    <td><?= htmlspecialchars(date('M j, Y g:i A', strtotime($r['rejected_at']))) ?></td>
                    <td><?= htmlspecialchars($r['rejected_by_name'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($r['reason'] ?? '—') ?></td>
                    <td>
                        <form method="POST" action="users.php#approvals" class="inline">
                            <input type="hidden" name="rejection_id" value="<?= (int) $r['rejection_id'] ?>">
                            <button type="submit" name="action" value="restore_rejected" class="btn-secondary">Restore</button>
                            <button type="submit" name="action" value="purge_rejected" class="btn-danger"
                                    onclick="return confirm('Permanently remove this rejected record?');">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php endif; ?>
