// --- assets/js/pages/notaire-contrats.js ---

document.addEventListener('DOMContentLoaded', function() {
    console.log("JS Notaire Contrats: DOM Loaded.");

    // --- Références DOM ---
    const filterForm = document.getElementById('filterContratsForm');
    const tableBody = document.getElementById('contratsTableBody');
    const paginationControls = document.getElementById('paginationControlsContrat'); // ID corrigé ? Vérifier HTML
    const loadingRow = tableBody.querySelector('.loading-row');
    const noResultsRow = tableBody.querySelector('.no-results-row');
    const resetFiltersBtn = document.getElementById('resetContratFiltersBtn');
    const pageAlertPlaceholder = document.getElementById('pageAlertPlaceholder'); // Pour erreurs générales

    // Modal Nouveau Contrat
    const modalNouveauContratEl = document.getElementById('modalNouveauContrat');
    const modalNouveauContrat = bootstrap.Modal.getOrCreateInstance(modalNouveauContratEl);
    const formNouveauContrat = document.getElementById('formNouveauContrat');
    const submitNouveauContratBtn = document.getElementById('submitNouveauContratBtn');
    const formNouveauContratError = document.getElementById('formNouveauContratError');
    const selectBien = document.getElementById('selectBienContrat');
    const selectLocataire = document.getElementById('selectLocataireContrat');
    const dateDebutInput = document.getElementById('dateDebutContrat');
    const dateFinInput = document.getElementById('dateFinContrat');

    // Modal Détails Contrat
    const detailsModalEl = document.getElementById('modalDetailsContrat');
    const detailsModal = bootstrap.Modal.getOrCreateInstance(detailsModalEl);
    const detailsModalContent = document.getElementById('detailsContratContenu');
    const modalDownloadBtn = document.getElementById('modalDownloadBtn');

    // Modal Signer Contrat (Notaire)
    const signModalEl = document.getElementById('modalSignerContrat');
    const signModal = bootstrap.Modal.getOrCreateInstance(signModalEl);
    const signContractForm = document.getElementById('signContractForm');
    const signContractIdInput = document.getElementById('signContractId');
    const signContractInfoDiv = document.getElementById('signContractInfo');
    const signFormFeedback = document.getElementById('signFormFeedback');
    const validateSignatureBtn = document.getElementById('validateSignatureBtn');

    // Endpoint pour les actions AJAX
    const ajaxEndpoint = 'ajax/notaire_contrats_actions.php'; // Chemin vers le fichier PHP AJAX

    // --- Variables globales ---
    let currentFilters = {};
    let currentPage = 1;

    // --- Initialiser Flatpickr ---
    const fpDebut = flatpickr(dateDebutInput, { dateFormat: "Y-m-d", locale: "fr", minDate: "today" });
    const fpFin = flatpickr(dateFinInput, { dateFormat: "Y-m-d", locale: "fr" });
    fpDebut.config.onChange.push(d => { if(d[0]) fpFin.set('minDate', d[0]); else fpFin.set('minDate', null); });

    // --- Fonctions Utilitaires (Identiques) ---
    function showAlert(p, m, t='danger') { /* ... */ if(p){const w=document.createElement('div'); w.innerHTML = `<div class="alert alert-${t} alert-dismissible fade show alert-sm mb-2" role="alert"><div>${m}</div><button type="button" class="btn-close btn-sm" data-bs-dismiss="alert"></button></div>`; p.innerHTML=''; p.append(w.firstChild);} }
    function clearAlert(p) { if(p) p.innerHTML=''; }
    function htmlspecialchars(s) { const map={'&':'&','<':'<','>':'>','"':'"',"'":'''}; return String(s).replace(/[&<>"']/g, m=>map[m]); }
    function setButtonLoading(btn, isLoading, text=''){ if(!btn) return; if(isLoading){ btn.disabled=true; if(!btn.dataset.original) btn.dataset.original=btn.innerHTML; btn.innerHTML='<span class="spinner-border spinner-border-sm"></span>'; btn.classList.add('loading'); } else { btn.disabled=false; btn.innerHTML = btn.dataset.original || text; btn.classList.remove('loading'); delete btn.dataset.original; } }

    // --- Création Ligne Tableau (Identique à la version notaire précédente) ---
    function createContratRow(c) { /* ... (Copier/coller la fonction createContratRow d'avant) ... */
        const row = document.createElement('tr'); row.setAttribute('data-contrat-id', c.idContrat);
        const periode = (c.dateDebut && c.dateFin) ? `${c.dateDebut} au ${c.dateFin}` : 'N/A';
        let statutBadgeClass = 'bg-light text-dark'; let statutText = c.statutContrat || '?'; switch(c.statutContrat) { case 'Actif': statutBadgeClass = 'bg-success'; break; case 'Résilié': statutBadgeClass = 'bg-danger'; break; case 'Expiré': statutBadgeClass = 'bg-secondary'; break; case 'En attente signatures': statutBadgeClass = 'bg-warning text-dark'; statutText = 'Att. Signatures'; break; case 'Signé par les parties': statutBadgeClass = 'bg-info text-dark'; statutText = 'Signé (Parties)'; break; }
        const sigP = c.sigP ? '<i class="ri-checkbox-circle-fill sig-status sig-ok" title="Prop: Signé"></i>' : '<i class="ri-time-line sig-status sig-pending" title="Prop: Attente"></i>';
        const sigL = c.sigL ? '<i class="ri-checkbox-circle-fill sig-status sig-ok" title="Loc: Signé"></i>' : '<i class="ri-time-line sig-status sig-pending" title="Loc: Attente"></i>';
        const sigN = c.sigN ? '<i class="ri-checkbox-circle-fill sig-status sig-ok" title="Notaire: Signé"></i>' : '<i class="ri-pencil-line sig-status sig-missing" title="Notaire: Attente"></i>';
        const canNotaireSign = c.sigP && c.sigL && !c.sigN;
        const signButtonHtml = canNotaireSign ? `<button onclick="openSignModal(${c.idContrat})" class="btn btn-sm btn-success" title="Signer/Activer"><i class="ri-check-double-line"></i></button>` : '';
        const downloadBtnDisabled = !c.hasDoc; const downloadBtnClass = `btn btn-sm btn-secondary ${downloadBtnDisabled ? 'disabled' : ''}`; const downloadBtnTitle = downloadBtnDisabled ? "Doc final indisponible" : "Télécharger PDF final";
        // Utilise le script principal pour le download
        const downloadAction = c.hasDoc ? `window.location.href='notaire-gestion-contrats.php?action=download_contract&id=${c.idContrat}'` : '';
        row.innerHTML = `<td>#${c.ref || c.idContrat}</td> <td><small>${htmlspecialchars(c.bienAdresse || '?')}</small></td> <td>${htmlspecialchars(c.locataireNom || '?')}</td> <td>${htmlspecialchars(c.proprietaireNom || '?')}</td> <td><small>${periode}</small></td> <td><span class="badge ${statutBadgeClass}">${htmlspecialchars(statutText)}</span></td> <td>${sigP}${sigL}${sigN}</td> <td><div class="d-flex gap-1 flex-wrap justify-content-end"> <button onclick="viewContratDetails(${c.idContrat})" class="btn btn-sm btn-info" title="Détails"><i class="ri-eye-line"></i></button> ${signButtonHtml} <button onclick="${downloadAction}" class="${downloadBtnClass}" title="${downloadBtnTitle}"><i class="ri-download-2-line"></i></button> <button onclick="deleteContrat(${c.idContrat})" class="btn btn-sm btn-danger" title="Supprimer"><i class="ri-delete-bin-line"></i></button> </div> </td>`; return row;
      }

    // --- Chargement des Contrats (Appelle le fichier AJAX) ---
    async function loadContrats(page = 1, filters = {}) {
        currentPage = page; currentFilters = filters;
        if (loadingRow) loadingRow.style.display = 'table-row';
        if (noResultsRow) noResultsRow.style.display = 'none';
        tableBody.querySelectorAll('tr:not(.loading-row):not(.no-results-row)').forEach(row => row.remove());
        paginationControls.innerHTML = '';

        const params = new URLSearchParams(currentFilters);
        params.append('action', 'get_contrats');
        params.append('page', currentPage);
        // Ajouter les filtres du formulaire explicitement
        const formData = new FormData(filterForm);
        formData.forEach((value, key) => { if(value) params.append(key, value); });

        const url = `${ajaxEndpoint}?${params.toString()}`; // Pointe vers le fichier AJAX
        console.log(`JS: Fetching: ${url}`);

        try {
            const response = await fetch(url);
            const data = await response.json();
            if (loadingRow) loadingRow.style.display = 'none';

            if (!response.ok) throw new Error(data.message || `Erreur HTTP ${response.status}`);
            if (!data.success) throw new Error(data.message || "Erreur serveur inconnue");

            tableBody.innerHTML = ''; // Vider après succès fetch
            if (data.contrats && data.contrats.length > 0) {
                data.contrats.forEach(contrat => tableBody.appendChild(createContratRow(contrat)));
            } else {
                 if (noResultsRow) { tableBody.appendChild(noResultsRow); noResultsRow.style.display = 'table-row'; noResultsRow.querySelector('td').textContent = "Aucun contrat trouvé."; }
            }
            updateContratPagination(data.pagination, currentPage); // Mettre à jour pagination
        } catch(e) {
            if (loadingRow) loadingRow.style.display = 'none';
             if (noResultsRow) { tableBody.appendChild(noResultsRow); noResultsRow.style.display = 'table-row'; noResultsRow.querySelector('td').textContent = `Erreur chargement: ${e.message}`; }
            console.error("Erreur loadContrats:", e);
            updateContratPagination(null, currentPage); // Reset pagination en cas d'erreur
            showAlert(pageAlertPlaceholder || document.body, `Erreur chargement: ${e.message}`, 'danger');
        }
    }

    // --- Chargement Détails Contrat (Appelle le fichier AJAX) ---
    window.viewContratDetails = async function(contratId) {
        detailsModalContent.innerHTML = '<p class="text-center py-5"><span class="spinner-border spinner-border-sm me-2"></span>Chargement...</p>';
        modalDownloadBtn.style.display = 'none'; modalDownloadBtn.classList.add('disabled'); modalDownloadBtn.href = '#';
        detailsModal.show();
        try {
            const url = `${ajaxEndpoint}?action=get_contrat_details&id=${contratId}`;
            const response = await fetch(url);
            const data = await response.json();
            if (!response.ok || !data.success) throw new Error(data.message || `Erreur ${response.status}`);
            const d = data.details;
            // --- Formatage (Identique) ---
            const formatCurrency = (val) => val ? `${parseFloat(val).toLocaleString('fr-FR')} FCFA` : 'N/A';
            const formatDate = (val) => val ? new Date(val).toLocaleDateString('fr-FR', { day: '2-digit', month: 'long', year: 'numeric', hour:'2-digit', minute:'2-digit' }) : 'N/A';
            const formatDateSimple = (val) => val ? new Date(val).toLocaleDateString('fr-FR') : 'N/A';
            const sigBadge = (dateVal) => dateVal ? `<span class="badge bg-success">Signé le ${formatDate(dateVal)}</span>` : '<span class="badge bg-warning text-dark">En attente</span>';
            const activationBadge = (dateVal) => dateVal ? `<span class="badge bg-success">Activé le ${formatDate(dateVal)}</span>` : '<span class="badge bg-secondary">Non Activé</span>';
            let statutBadgeClass = 'bg-secondary'; switch(d.statutContrat) { case 'Actif': statutBadgeClass = 'bg-success'; break; case 'Résilié': statutBadgeClass = 'bg-danger'; break; case 'Expiré': statutBadgeClass = 'bg-secondary'; break; case 'En attente signatures': statutBadgeClass = 'bg-warning text-dark'; break; case 'Signé par les parties': statutBadgeClass = 'bg-info text-dark'; break; }
            const downloadHtml = d.downloadUrl ? `<p class="text-success mb-1">Document final disponible.</p>` : `<p class="text-muted fst-italic mb-1">Document final non disponible.</p>`;
            detailsModalContent.innerHTML = `... (coller le HTML formaté du modal détails ici) ...`; // Comme avant
            if (d.downloadUrl) { modalDownloadBtn.href = d.downloadUrl; modalDownloadBtn.classList.remove('disabled'); modalDownloadBtn.style.display = 'inline-block'; }
        } catch (error) { detailsModalContent.innerHTML = `<div class="alert alert-danger">Erreur: ${error.message}</div>`; }
    }

    // --- Chargement Options Modal Nouveau (Appelle le fichier AJAX) ---
    async function loadSelectOptions() {
        selectBien.disabled = true; selectLocataire.disabled = true;
        selectBien.innerHTML = '<option value="" selected disabled>Chargement...</option>';
        selectLocataire.innerHTML = '<option value="" selected disabled>Chargement...</option>';
        clearAlert(formNouveauContratError);
        try {
            const url = `${ajaxEndpoint}?action=get_options`;
            const response = await fetch(url);
            const data = await response.json();
            if (!response.ok || !data.success) throw new Error(data.message || "Erreur chargement options.");
            selectBien.innerHTML = '<option value="" selected disabled>-- Sélectionner --</option>';
            if (data.biens && data.biens.length > 0) { data.biens.forEach(b => selectBien.add(new Option(b.description, b.idBien))); selectBien.disabled = false; } else { selectBien.innerHTML = '<option value="" disabled>Aucun bien</option>'; }
            selectLocataire.innerHTML = '<option value="" selected disabled>-- Sélectionner --</option>';
            if (data.locataires && data.locataires.length > 0) { data.locataires.forEach(l => selectLocataire.add(new Option(l.description, l.idLocataire))); selectLocataire.disabled = false; } else { selectLocataire.innerHTML = '<option value="" disabled>Aucun locataire</option>'; }
        } catch (error) { displayError(formNouveauContratError, `Options: ${error.message}`); selectBien.innerHTML = '<option value="" disabled>Erreur</option>'; selectLocataire.innerHTML = '<option value="" disabled>Erreur</option>'; }
    }

    // --- Soumission Nouveau Contrat (Appelle le fichier AJAX) ---
    async function handleNouveauContratSubmit(e) {
        e.preventDefault(); e.stopPropagation(); clearAlert(formNouveauContratError);
        // --- Validation (Identique) ---
        formNouveauContrat.classList.remove('was-validated'); dateDebutInput.classList.remove('is-invalid'); dateFinInput.classList.remove('is-invalid');
        const dateDebut = fpDebut.selectedDates[0]; const dateFin = fpFin.selectedDates[0]; let datesValides = true; if (!dateDebut) { dateDebutInput.classList.add('is-invalid'); datesValides = false; } if (!dateFin) { dateFinInput.classList.add('is-invalid'); datesValides = false; } if(dateDebut && dateFin && dateFin <= dateDebut) { dateFinInput.classList.add('is-invalid'); if (!formNouveauContratError.textContent) displayError(formNouveauContratError, "Date fin > début."); datesValides = false; } let formValide = formNouveauContrat.checkValidity(); if (!formValide || !datesValides) { formNouveauContrat.classList.add('was-validated'); if (!formNouveauContratError.textContent) displayError(formNouveauContratError, "Corrigez les erreurs."); return; }

        const formData = new FormData(formNouveauContrat); formData.append('action', 'create_contrat');
        const originalButtonText = submitNouveauContratBtn.innerHTML; setButtonLoading(submitNouveauContratBtn, true);
        try {
            const url = ajaxEndpoint; // Pointe vers le fichier AJAX
            const response = await fetch(url, { method: 'POST', body: formData });
            const data = await response.json();
            if (!response.ok || !data.success) throw new Error(data.message || `Erreur serveur ${response.status}`);
            const newRow = createContratRow(data.newContrat);
            if (noResultsRow) noResultsRow.style.display = 'none'; // Cacher "aucun résultat"
            tableBody.prepend(newRow); // Ajouter en haut
            modalNouveauContrat.hide();
            Swal.fire('Succès!', data.message || 'Contrat créé!', 'success'); // Utilisation SweetAlert
            formNouveauContrat.reset(); formNouveauContrat.classList.remove('was-validated'); fpDebut.clear(); fpFin.clear(); fpFin.set('minDate', null);
            // Recharger les options peut être utile si un locataire/bien a été utilisé
            // loadSelectOptions(); // Optionnel
        } catch (error) { displayError(formNouveauContratError, error.message); }
        finally { setButtonLoading(submitNouveauContratBtn, false, originalButtonText); }
    }

    // --- Ouverture Modal Signature Notaire (Appelle le fichier AJAX pour infos) ---
    window.openSignModal = async function(contratId) {
        signContractForm.reset(); signFormFeedback.innerHTML = ''; validateSignatureBtn.disabled = false; validateSignatureBtn.innerHTML = '<i class="ri-check-line me-1"></i>Confirmer et Activer';
        signContractInfoDiv.innerHTML = '<p class="text-center text-muted">Chargement...</p>';
        signContractIdInput.value = contratId; signModal.show();
        try { // Fetch infos contrat pour affichage
            const url = `${ajaxEndpoint}?action=get_contrat_details&id=${contratId}`;
            const response = await fetch(url); const data = await response.json();
            if (data.success && data.details) { const d = data.details; signContractInfoDiv.innerHTML = `<div class="row"><div class="col-sm-4"><strong>Contrat Réf:</strong></div><div class="col-sm-8">#${d.idContrat}</div></div><div class="row"><div class="col-sm-4"><strong>Bien:</strong></div><div class="col-sm-8"><small>${htmlspecialchars(d.bienAdresse)}</small></div></div><div class="row"><div class="col-sm-4"><strong>Parties:</strong></div><div class="col-sm-8">${htmlspecialchars(d.proprioFullName)} / ${htmlspecialchars(d.locataireFullName)}</div></div>`; }
            else { signContractInfoDiv.innerHTML = '<p class="text-danger">Erreur chargement infos.</p>'; }
        } catch (error) { signContractInfoDiv.innerHTML = '<p class="text-danger">Erreur réseau.</p>'; }
    };

    // --- Soumission Signature Notaire (Appelle le fichier AJAX) ---
    signContractForm.addEventListener('submit', async function(event) {
        event.preventDefault(); signFormFeedback.innerHTML = '';
        const contractId = signContractIdInput.value; if (!contractId) { showAlert(signFormFeedback, 'ID contrat manquant.', 'danger'); return; }
        const originalButtonText = validateSignatureBtn.innerHTML; setButtonLoading(validateSignatureBtn, true);
        const formData = new FormData(); formData.append('action', 'sign_contrat_notaire'); formData.append('contractId', contractId);
        try {
            const url = ajaxEndpoint; // Pointe vers le fichier AJAX
            const response = await fetch(url, { method: 'POST', body: formData });
            const data = await response.json();
            if (!response.ok || !data.success) throw new Error(data.message || `Erreur serveur ${response.status}`);
            showAlert(signFormFeedback, data.message || "Signature enregistrée!", 'success');
            setTimeout(() => { signModal.hide(); loadContrats(currentPage, currentFilters); }, 1500); // Recharger la page actuelle
        } catch (error) { showAlert(signFormFeedback, error.message, 'danger'); }
        finally { setButtonLoading(validateSignatureBtn, false, originalButtonText); }
    });

    // --- Mise à jour Visuelle Ligne (Identique) ---
    function updateTableRowStatus(contratId, updatedData) { /* ... (Copier/coller la fonction updateTableRowStatus d'avant) ... */
         const row = tableBody.querySelector(`tr[data-contrat-id="${contratId}"]`); if (!row) { console.warn("Ligne MAJ non trouvée:", contratId); loadContrats(currentPage); return; }
         if (updatedData.hasOwnProperty('sigN')) { const sigIcons = row.cells[6].querySelectorAll('.sig-status'); if (sigIcons.length === 3) { sigIcons[2].className = updatedData.sigN ? 'ri-checkbox-circle-fill sig-status sig-ok' : 'ri-pencil-line sig-status sig-missing'; sigIcons[2].title = updatedData.sigN ? 'Notaire: Signé' : 'Notaire: Attente'; } }
         if (updatedData.statutContrat) { let newBadgeClass = 'bg-light text-dark'; let newStatutText = updatedData.statutContrat; switch(updatedData.statutContrat) { case 'Actif': newBadgeClass = 'bg-success'; break; case 'Résilié': newBadgeClass = 'bg-danger'; break; case 'Expiré': newBadgeClass = 'bg-secondary'; break; case 'En attente signatures': newBadgeClass = 'bg-warning text-dark'; newStatutText = 'Att. Signatures'; break; case 'Signé par les parties': newBadgeClass = 'bg-info text-dark'; newStatutText = 'Signé (Parties)'; break; } row.cells[5].innerHTML = `<span class="badge ${newBadgeClass}">${htmlspecialchars(newStatutText)}</span>`; }
         if (updatedData.hasOwnProperty('hasDoc')) { const downloadBtn = row.cells[7].querySelector('button[onclick^="downloadSignedContract"]'); if (downloadBtn) { downloadBtn.classList.toggle('disabled', !updatedData.hasDoc); downloadBtn.title = !updatedData.hasDoc ? "Doc final indisponible" : "Télécharger PDF final"; downloadBtn.onclick = updatedData.hasDoc ? () => downloadSignedContract(contratId) : null; } }
         const canSignNow = (row.cells[6].querySelector('.sig-status:nth-child(1)')?.classList.contains('sig-ok')) && (row.cells[6].querySelector('.sig-status:nth-child(2)')?.classList.contains('sig-ok')) && !(updatedData.sigN ?? row.cells[6].querySelector('.sig-status:nth-child(3)')?.classList.contains('sig-ok'));
         const existingSignBtn = row.cells[7].querySelector('button[onclick^="openSignModal"]'); if (canSignNow && !existingSignBtn) { const signBtn = document.createElement('button'); signBtn.className = 'btn btn-sm btn-success'; signBtn.title = 'Signer/Activer'; signBtn.innerHTML = '<i class="ri-check-double-line"></i>'; signBtn.onclick = () => openSignModal(contratId); row.cells[7].querySelector('.d-flex').insertBefore(signBtn, row.cells[7].querySelector('button[onclick^="downloadSignedContract"]')); } else if (!canSignNow && existingSignBtn) { existingSignBtn.remove(); }
     }

    // --- Supprimer Contrat (Appelle le fichier AJAX) ---
    window.deleteContrat = async function(contratId) {
        Swal.fire({ title: 'Sûr?', text: `Supprimer Contrat #${contratId} et remettre bien en 'Libre'? Irréversible!`, icon: 'warning', showCancelButton: true, confirmButtonColor: '#d33', confirmButtonText: 'Oui, supprimer!', cancelButtonText: 'Annuler'
        }).then(async (result) => { if (result.isConfirmed) { const row = tableBody.querySelector(`tr[data-contrat-id="${contratId}"]`); if (row) row.style.opacity = '0.5'; const formData = new FormData(); formData.append('action', 'delete_contrat'); formData.append('id', contratId); try { const url = ajaxEndpoint; // Pointe vers le fichier AJAX
                    const response = await fetch(url, { method: 'POST', body: formData }); const data = await response.json(); if (!response.ok || !data.success) throw new Error(data.message || `Erreur ${response.status}`); Swal.fire('Supprimé!', data.message || 'Contrat supprimé.', 'success'); loadContrats(currentPage); } catch (error) { Swal.fire('Erreur', `Erreur suppression: ${error.message}`, 'error'); if (row) row.style.opacity = '1'; } } });
    };

     // --- Télécharger Contrat (Appelle le fichier PHP principal) ---
     window.downloadSignedContract = (id) => {
         const btn = event.target.closest('button'); if (btn && btn.classList.contains('disabled')) return;
         const downloadUrl = `notaire-gestion-contrats.php?action=download_contract&id=${id}`; // Pointe vers le fichier principal
         console.log("JS: Attempting download from:", downloadUrl);
         window.location.href = downloadUrl; // Rediriger pour lancer le téléchargement
     };

    // --- Écouteurs (Identiques) ---
    formNouveauContrat.addEventListener('submit', handleNouveauContratSubmit);
    modalNouveauContratEl.addEventListener('show.bs.modal', loadSelectOptions);
    modalNouveauContratEl.addEventListener('hidden.bs.modal',()=>{ /* ... reset form ... */ formNouveauContrat.reset(); formNouveauContrat.classList.remove('was-validated'); clearAlert(formNouveauContratError); fpDebut.clear(); fpFin.clear(); fpFin.set('minDate', null); });
    filterForm.addEventListener('submit', (e) => { e.preventDefault(); const fd=new FormData(filterForm); currentFilters=Object.fromEntries(fd.entries()); loadContrats(1, currentFilters); });
    resetFiltersBtn.addEventListener('click', () => { filterForm.reset(); currentFilters={}; loadContrats(); });

    // --- Chargement Initial ---
    loadContrats(); // Lance le chargement de la table

    console.log("JS Notaire Contrats: Initialized.");

}); // Fin DOMContentLoaded

// --- Fonction Pagination (Identique) ---
function updateContratPagination(paginationInfo, currentPage) { /* ... (Copier/coller la fonction updateContratPagination d'avant) ... */
     const paginationControls = document.getElementById('paginationControlsContrat'); paginationControls.innerHTML = ''; if (!paginationInfo || paginationInfo.totalPages <= 1) return; const totalPages = paginationInfo.totalPages; const createPageItem = (page, label = null, isActive = false, isDisabled = false) => { const li = document.createElement('li'); li.className = `page-item ${isActive ? 'active' : ''} ${isDisabled ? 'disabled' : ''}`; const a = document.createElement('a'); a.className = 'page-link'; a.href = '#'; a.innerHTML = label !== null ? label : page; if (!isDisabled && label !== '...') { a.dataset.page = page; a.addEventListener('click', (e) => { e.preventDefault(); const targetPage = parseInt(a.dataset.page); if (!isNaN(targetPage)) { loadContrats(targetPage); } }); } else { a.setAttribute('aria-disabled', 'true'); a.style.cursor = 'default'; } li.appendChild(a); return li; }; paginationControls.appendChild(createPageItem(currentPage - 1, '«', false, currentPage === 1)); const maxPagesToShow = 5; let startPage, endPage; if (totalPages <= maxPagesToShow) { startPage = 1; endPage = totalPages; } else { const maxSide = Math.floor((maxPagesToShow - 3) / 2); let Pstart = currentPage - maxSide; let Pend = currentPage + maxSide; if (maxPagesToShow === 5 && currentPage > 2 && currentPage < totalPages -1) {Pstart=currentPage-1; Pend=currentPage+1;} else if (currentPage <=3) {Pstart=1; Pend=maxPagesToShow-2;} else if (currentPage >= totalPages-2) {Pstart = totalPages - maxPagesToShow + 3; Pend=totalPages;} startPage = Pstart; endPage = Pend; } if (startPage > 1) paginationControls.appendChild(createPageItem(1)); if (startPage > 2) paginationControls.appendChild(createPageItem(null, '...', false, true)); for (let i = startPage; i <= endPage; i++) { if (i > 0 && i <= totalPages) paginationControls.appendChild(createPageItem(i, null, i === currentPage)); } if (endPage < totalPages - 1) paginationControls.appendChild(createPageItem(null, '...', false, true)); if (endPage < totalPages) paginationControls.appendChild(createPageItem(totalPages)); paginationControls.appendChild(createPageItem(currentPage + 1, '»', false, currentPage === totalPages));
 }
</script>

</body>
</html>