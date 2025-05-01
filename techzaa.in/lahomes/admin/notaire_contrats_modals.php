<!-- includes/notaire_contrats_modals.php -->

<!-- Modal Nouveau Contrat -->
<div class="modal fade" id="modalNouveauContrat" tabindex="-1" aria-labelledby="modalNouveauContratLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalNouveauContratLabel">Créer un Nouveau Contrat</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="formNouveauContratError" class="alert alert-danger d-none" role="alert"></div>
                <form id="formNouveauContrat" novalidate> <!-- Enctype enlevé car fichier optionnel -->
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="selectBienContrat" class="form-label">Bien Immobilier <span class="text-danger">*</span></label>
                            <select class="form-select form-select-sm" id="selectBienContrat" name="idBien" required>
                                <option value="" selected disabled>-- Chargement... --</option>
                            </select>
                            <div class="invalid-feedback">Veuillez sélectionner un bien.</div>
                        </div>
                         <div class="col-md-6">
                            <label for="selectLocataireContrat" class="form-label">Locataire <span class="text-danger">*</span></label>
                            <select class="form-select form-select-sm" id="selectLocataireContrat" name="idLocataire" required>
                                <option value="" selected disabled>-- Chargement... --</option>
                            </select>
                            <div class="invalid-feedback">Veuillez sélectionner un locataire.</div>
                        </div>
                    </div>
                     <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="dateDebutContrat" class="form-label">Date Début <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-sm flatpickr-input" id="dateDebutContrat" name="dateDebut" required placeholder="YYYY-MM-DD">
                            <div class="invalid-feedback">Date début invalide.</div>
                        </div>
                        <div class="col-md-6">
                            <label for="dateFinContrat" class="form-label">Date Fin <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-sm flatpickr-input" id="dateFinContrat" name="dateFin" required placeholder="YYYY-MM-DD">
                            <div class="invalid-feedback">Date fin invalide (> début).</div>
                        </div>
                    </div>
                     <div class="row g-3 mb-3">
                        <div class="col-md-4">
                             <label for="montantLoyerContrat" class="form-label">Loyer Mensuel (FCFA) <span class="text-danger">*</span></label>
                             <input type="number" class="form-control form-control-sm" id="montantLoyerContrat" name="montantLoyer" required placeholder="Ex: 150000" min="0" step="any">
                             <div class="invalid-feedback">Montant invalide (>= 0).</div>
                         </div>
                         <div class="col-md-4">
                             <label for="cautionContrat" class="form-label">Caution (FCFA)</label>
                             <input type="number" class="form-control form-control-sm" id="cautionContrat" name="caution" placeholder="Défaut: 1 mois loyer" min="0" step="any">
                         </div>
                         <div class="col-md-4">
                             <label for="moisAvanceContrat" class="form-label">Mois Avance</label>
                             <input type="number" class="form-control form-control-sm" id="moisAvanceContrat" name="moisAvance" placeholder="Défaut: 3 mois" min="0" step="1">
                         </div>
                    </div>
                     <!-- Option Fichier Initial (gardé mais facultatif) -->
                    <!-- <div class="mb-3"> <label for="uploadContratFile" class="form-label">Joindre Doc Initial (PDF)</label> <input class="form-control form-control-sm" type="file" id="uploadContratFile" name="fichierContrat" accept=".pdf"> <div class="form-text">Optionnel. Utile comme référence.</div> <div class="invalid-feedback">Fichier PDF requis (max 5Mo).</div> </div> -->
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Annuler</button>
                <button type="submit" form="formNouveauContrat" class="btn btn-primary btn-sm" id="submitNouveauContratBtn">Enregistrer</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal Détails Contrat -->
<div class="modal fade" id="modalDetailsContrat" tabindex="-1" aria-labelledby="modalDetailsContratLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title" id="modalDetailsContratLabel">Détails Contrat</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="detailsContratContenu">
                <p class="text-center py-5">Chargement...</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Fermer</button>
                 <a href="#" id="modalDownloadBtn" class="btn btn-primary btn-sm disabled" target="_blank" download style="display: none;"><i class="ri-download-2-line me-1"></i> Télécharger PDF Final</a>
            </div>
        </div>
    </div>
</div>

<!-- Modal Signer Contrat (Notaire) -->
<div class="modal fade" id="modalSignerContrat" tabindex="-1" aria-labelledby="modalSignerContratLabel" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title" id="modalSignerContratLabel">Signer/Activer Contrat</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="signContractForm">
                <div class="modal-body">
                    <input type="hidden" id="signContractId" name="contractId">
                    <h6 class="mb-3">Contrat Concerné</h6>
                    <div id="signContractInfo" class="mb-4 p-3 bg-light rounded border">
                        <p class="text-center text-muted">Chargement...</p>
                    </div>
                    <h6 class="mb-3">Confirmation Notaire</h6>
                    <p>Confirmez-vous avoir vérifié les signatures du propriétaire et du locataire et souhaitez-vous activer ce contrat ?</p>
                    <div id="signFormFeedback" class="mt-3"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-success btn-sm" id="validateSignatureBtn"><i class="ri-check-double-line me-1"></i>Confirmer et Activer</button>
                </div>
            </form>
        </div>
    </div>
</div>