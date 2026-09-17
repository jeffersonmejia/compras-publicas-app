import { HttpClient, HttpHeaders } from '@angular/common/http';
import { Component, inject, OnInit } from '@angular/core';
import { DomSanitizer, SafeResourceUrl } from '@angular/platform-browser';
import { DatePipe } from '@angular/common';
import { FormsModule } from '@angular/forms';

type RoleCode = 'contratacion_publica' | 'bienes_activos_fijos' | 'contador' | 'director' | 'operador';
type SessionData = { token: string; expiresAt: number; user: { id: number; username: string; name: string; cedula: string; lastName?: string; role: RoleCode; subrole: string | null } };
type CloudFile = { name: string; type: 'file' | 'folder' };
type TreeNode = { name: string; type: 'folder'; path: string; expanded: boolean; loaded: boolean; children: TreeNode[] };
type PreRegistration = { id: number; cedula: string; role_code: string; status: string; first_names: string | null; last_names: string | null; is_active: number; created_by: number; created_at: string };
type RoleItem = { code: string; label: string; parent_role: string | null; is_active: number };
type PurchaseItem = { id: number; name: string; slug: string; folder: string; delegated_name: string; delegated_last_name: string; role: string; subrole: string | null; is_active: number };
type DelegatedUser = { id: number; username: string; name: string; last_name: string; role: string; subrole: string | null };

@Component({
  selector: 'app-root',
  imports: [FormsModule, DatePipe],
  templateUrl: './app.html',
  styleUrl: './app.scss',
})
export class App implements OnInit {
  private readonly http = inject(HttpClient);
  private readonly sanitizer = inject(DomSanitizer);
  private readonly storageKey = 'compras-publicas-session';

  username = '';
  password = '';
  remember = false;
  showPassword = false;
  loginMessage = '';
  isSubmitting = false;
  authMode: 'login' | 'recovery' | 'registration' | 'home' = 'login';
  recoveryUsername = '';
  session: SessionData | null = null;
  isRestoringSession = true;
  files: CloudFile[] = [];
  selectedFileRole = 'mine';
  allowedExtensions: string[] = ['pdf'];
  isUploading = false;
  isLoadingFiles = false;
  uploadMessage = '';
  currentFolder = '';
  rootFolderName = '';
  currentPath = '';
  treeNodes: TreeNode[] = [];
  previewFileName = '';
  previewUrl: string | null = null;
  previewSafeUrl: SafeResourceUrl | null = null;
  isCategoryView = false;
  folderModal: 'create' | 'rename' | null = null;
  folderName = '';
  folderToRename = '';
  deleteTarget: CloudFile | null = null;
  activeHomeView: 'files' | 'pre-registration' | 'roles' | 'delegation' = 'files';
  cedula = '';
  preRegistrationRole = 'operador';
  preRegistrationMessage = '';
  preRegistrations: PreRegistration[] = [];
  isLoadingPreRegistrations = false;
  roles: RoleItem[] = [];
  isLoadingRoles = false;
  roleCode = '';
  roleLabel = '';
  roleParent = '';
  roleMessage = '';
  rolePage = 1;
  rolePages = 1;
  purchases: PurchaseItem[] = [];
  purchaseName = '';
  purchaseRole = 'operador';
  purchaseSubrole = '';
  purchaseSearch = '';
  purchaseUsers: DelegatedUser[] = [];
  selectedPurchaseUser: DelegatedUser | null = null;
  purchaseMessage = '';
  isSearchingPurchaseUsers = false;
  purchaseSearchModal = false;
  preRegistrationPage = 1;
  preRegistrationPages = 1;
  selectedPreRegistration: PreRegistration | null = null;
  showRegistrationInfo = false;
  registrationCedula = '';
  verifiedCedula = '';
  firstNames = '';
  lastNames = '';
  registrationMessage = '';

  ngOnInit(): void {
    const rawSession = localStorage.getItem(this.storageKey);
    if (!rawSession) {
      this.isRestoringSession = false;
      this.navigate('/login');
      return;
    }

    try {
      const session = JSON.parse(rawSession) as SessionData;
      if (session.expiresAt * 1000 <= Date.now()) return this.logout();
      this.http.get(`${this.apiBase}/session`, { headers: new HttpHeaders({ Authorization: `Bearer ${session.token}` }) }).subscribe({
        next: () => { this.session = session; this.authMode = 'home'; this.isRestoringSession = false; this.navigate('/home'); this.loadDocumentConfig(); this.loadFiles(); },
        error: () => this.logout(),
      });
    } catch { this.logout(); }
  }

  get sessionRoleLabel(): string {
    const labels: Record<RoleCode, string> = {
      contratacion_publica: 'Contratación Pública', bienes_activos_fijos: 'Bienes y Activos Fijos', contador: 'Contador', director: 'Director', operador: 'Operador',
    };
    const subroles: Record<string, string> = { administrativo: 'Administrativo', financiero: 'Financiero', medico: 'Médico', planificacion: 'Planificación', informatica: 'Informática', talento_humano: 'Talento Humano', comunicacion: 'Comunicación' };
    if (!this.session) return '';
    const role = labels[this.session.user.role];
    const subrole = this.session.user.subrole ? subroles[this.session.user.subrole] : '';
    return subrole ? `${role} (${subrole})` : role;
  }
  get sessionRoleIcon(): string { const icons: Record<string, string> = { administrativo: 'admin_panel_settings', financiero: 'account_balance', medico: 'medical_services', planificacion: 'event_note', informatica: 'computer', talento_humano: 'groups', comunicacion: 'campaign', operador: 'manage_accounts' }; return this.session?.user.subrole ? (icons[this.session.user.subrole] ?? 'manage_accounts') : (this.session?.user.role === 'operador' ? 'manage_accounts' : 'badge'); }

  get apiBase(): string { return `http://${window.location.hostname}/compras-publicas-api/api/auth`; }
  get rootApi(): string { return `http://${window.location.hostname}/compras-publicas-api/api`; }
  get fileAccept(): string { return this.allowedExtensions.map((extension) => `.${extension}`).join(','); }
  get canPreRegister(): boolean { return this.session?.user.role === 'operador' && this.session.user.subrole === 'informatica'; }
  get canDelegatePurchases(): boolean { return this.session?.user.role === 'director' && this.session.user.subrole === 'administrativo'; }
  get canUploadCurrentPath(): boolean { const root = this.currentPath.split('/')[0].toLowerCase(); return root === 'procesos' || root === 'pagos'; }
  get canManageRoles(): boolean { return this.canPreRegister; }
  get selectedFileRoleLabel(): string { const labels: Record<string, string> = { contratacion_publica: 'Contratación Pública', bienes_activos_fijos: 'Bienes y Activos Fijos', contador: 'Contador', director: 'Director', informatica: 'Informática', talento_humano: 'Talento Humano', comunicacion: 'Comunicación', mine: 'Mi carpeta' }; return this.selectedFileRole === 'mine' && this.currentFolder ? this.currentFolder : (labels[this.selectedFileRole] ?? 'Filtrar'); }
  get rootFolderLabel(): string { return this.rootFolderName || this.selectedFileRoleLabel; }
  get treeEntries(): Array<{ node: TreeNode; depth: number }> {
    const entries: Array<{ node: TreeNode; depth: number }> = [];
    const visit = (nodes: TreeNode[], depth: number): void => nodes.forEach(node => { entries.push({ node, depth }); if (node.expanded) visit(node.children, depth + 1); });
    visit(this.treeNodes, 0);
    return entries;
  }

  openRecovery(): void { this.loginMessage = ''; this.authMode = 'recovery'; }
  openRegistration(): void { this.loginMessage = ''; this.registrationMessage = ''; this.authMode = 'registration'; }
  closeRecovery(): void { this.authMode = 'login'; }
  requestRecovery(): void { /* Pendiente de configurar correo institucional. */ }

  verifyPreRegistration(): void {
    this.registrationMessage = '';
    this.http.get<{ cedula: string; role_code: string }>(`${this.rootApi}/registration/verify?cedula=${encodeURIComponent(this.registrationCedula)}`).subscribe({
      next: ({ cedula, role_code }) => { this.verifiedCedula = cedula; this.preRegistrationRole = role_code; },
      error: (error) => this.registrationMessage = error.error?.message ?? 'No se pudo verificar el pre-registro.',
    });
  }

  completeRegistration(): void {
    this.registrationMessage = '';
    this.http.post(`${this.rootApi}/registration/complete`, { cedula: this.verifiedCedula, firstNames: this.firstNames, lastNames: this.lastNames }).subscribe({
      next: () => this.registrationMessage = 'Datos registrados correctamente. Su cuenta está lista para ser activada.',
      error: (error) => this.registrationMessage = error.error?.message ?? 'No se pudo completar el registro.',
    });
  }

  logout(): void {
    localStorage.removeItem(this.storageKey);
    this.session = null;
    this.password = '';
    this.authMode = 'login';
    this.isRestoringSession = false;
    this.navigate('/login');
  }

  loadFiles(clearMessage = true, path = this.currentPath): void {
    if (!this.session) return;
    this.isLoadingFiles = true;
    if (clearMessage) this.uploadMessage = '';
    const role = this.session.user.role === 'contratacion_publica' ? this.selectedFileRole : this.session.user.role;
    this.currentPath = path;
    const query = `role=${encodeURIComponent(role)}&path=${encodeURIComponent(path)}`;
    this.http.get<{ files: CloudFile[]; folder: string; path: string; category: boolean }>(`${this.rootApi}/documents?${query}`, { headers: new HttpHeaders({ Authorization: `Bearer ${this.session.token}` }) }).subscribe({ next: ({ files, folder, category }) => { this.files = files; this.currentFolder = folder; this.isCategoryView = category; this.isLoadingFiles = false; if (!category && path === this.currentPath) { if (path === '') { this.rootFolderName = folder; this.setTreeRoot(files); } else { const node = this.findTreeNode(path); if (node) this.setTreeChildren(node, files); } } }, error: () => { this.files = []; this.currentFolder = ''; this.isCategoryView = false; this.isLoadingFiles = false; this.uploadMessage = 'No se pudo consultar la carpeta de archivos.'; } });
  }

  loadDocumentConfig(): void {
    if (!this.session) return;
    this.http.get<{ extensions: string[] }>(`${this.rootApi}/documents/config`, { headers: new HttpHeaders({ Authorization: `Bearer ${this.session.token}` }) }).subscribe({ next: data => this.allowedExtensions = data.extensions });
  }

  chooseFileFilter(role: string): void { if (this.isLoadingFiles || this.isUploading) return; this.selectedFileRole = role; this.currentPath = ''; this.treeNodes = []; this.loadFiles(); }

  private setTreeRoot(files: CloudFile[]): void { this.treeNodes = files.filter(file => file.type === 'folder').map(file => ({ name: file.name, type: 'folder', path: file.name, expanded: false, loaded: false, children: [] })); }
  private setTreeChildren(node: TreeNode, files: CloudFile[]): void { node.children = files.filter(file => file.type === 'folder').map(file => ({ name: file.name, type: 'folder', path: `${node.path}/${file.name}`, expanded: false, loaded: false, children: [] })); node.loaded = true; }
  private findTreeNode(path: string, nodes = this.treeNodes): TreeNode | null { for (const node of nodes) { if (node.path === path) return node; const nested = this.findTreeNode(path, node.children); if (nested) return nested; } return null; }
  toggleTreeNode(node: TreeNode): void {
    node.expanded = !node.expanded;
    if (node.expanded && !node.loaded && this.session) {
      this.http.get<{ files: CloudFile[] }>(`${this.rootApi}/documents?role=${encodeURIComponent(this.selectedFileRole)}&path=${encodeURIComponent(node.path)}`, { headers: new HttpHeaders({ Authorization: `Bearer ${this.session.token}` }) }).subscribe({ next: ({ files }) => this.setTreeChildren(node, files), error: () => { node.loaded = true; } });
    }
  }
  openFolder(path: string): void { if (this.isCategoryView) return; this.loadFiles(true, path); }
  goToRootFolder(): void { this.openFolder(''); }
  goToTreeFolder(node: TreeNode): void { this.openFolder(node.path); }
  isProtectedFolder(name: string): boolean { return this.currentPath === '' && ['procesos', 'pagos'].includes(name.toLowerCase()); }

  openCreateFolder(): void { if (this.isLoadingFiles || this.isUploading) return; this.folderName = ''; this.folderToRename = ''; this.folderModal = 'create'; }
  openRenameFolder(name: string): void { this.folderName = name; this.folderToRename = name; this.folderModal = 'rename'; }
  closeFolderModal(): void { this.folderModal = null; this.folderName = ''; this.folderToRename = ''; }
  saveFolder(): void {
    if (!this.session || !this.folderName.trim()) return;
    const headers = new HttpHeaders({ Authorization: `Bearer ${this.session.token}` });
    const request = this.folderModal === 'create'
      ? this.http.post(`${this.rootApi}/documents/folders`, { name: this.folderName.trim(), path: this.currentPath }, { headers })
      : this.http.patch(`${this.rootApi}/documents/folders/${encodeURIComponent(this.folderToRename)}`, { name: this.folderName.trim(), path: this.currentPath }, { headers });
    request.subscribe({ next: () => { this.uploadMessage = this.folderModal === 'create' ? 'Carpeta creada correctamente.' : 'Carpeta renombrada correctamente.'; this.closeFolderModal(); this.loadFiles(false); }, error: error => this.uploadMessage = error.error?.message ?? 'No se pudo guardar la carpeta.' });
  }
  requestDelete(file: CloudFile): void { if (file.type === 'folder' && this.currentPath === '' && ['procesos', 'pagos'].includes(file.name.toLowerCase())) return; this.deleteTarget = file; }
  cancelDelete(): void { this.deleteTarget = null; }
  confirmDelete(): void {
    if (!this.session || !this.deleteTarget) return;
    const target = this.deleteTarget; const query = this.currentPath ? `?path=${encodeURIComponent(this.currentPath)}` : ''; const url = target.type === 'folder' ? `${this.rootApi}/documents/folders/${encodeURIComponent(target.name)}${query}` : `${this.rootApi}/documents/${encodeURIComponent(target.name)}${query}`;
    this.http.delete(url, { headers: new HttpHeaders({ Authorization: `Bearer ${this.session.token}` }) }).subscribe({ next: () => { this.deleteTarget = null; this.uploadMessage = target.type === 'folder' ? 'Carpeta eliminada correctamente.' : 'Archivo eliminado correctamente.'; this.loadFiles(false); }, error: error => { this.deleteTarget = null; this.uploadMessage = error.error?.message ?? 'No se pudo eliminar el elemento.'; } });
  }
  downloadFile(name: string): void {
    if (!this.session) return;
    const query = this.currentPath ? `?path=${encodeURIComponent(this.currentPath)}` : ''; this.http.get(`${this.rootApi}/documents/${encodeURIComponent(name)}${query}`, { headers: new HttpHeaders({ Authorization: `Bearer ${this.session.token}` }), responseType: 'blob' }).subscribe({ next: blob => { const url = URL.createObjectURL(blob); const link = document.createElement('a'); link.href = url; link.download = name; link.click(); URL.revokeObjectURL(url); }, error: error => this.uploadMessage = error.error?.message ?? 'No se pudo descargar el archivo.' });
  }
  previewPdf(name: string): void {
    if (!this.session || !name.toLowerCase().endsWith('.pdf')) return;
    if (this.previewUrl) URL.revokeObjectURL(this.previewUrl);
    const query = `?inline=1${this.currentPath ? `&path=${encodeURIComponent(this.currentPath)}` : ''}`;
    this.http.get(`${this.rootApi}/documents/${encodeURIComponent(name)}${query}`, { headers: new HttpHeaders({ Authorization: `Bearer ${this.session.token}` }), responseType: 'blob' }).subscribe({ next: blob => { this.previewFileName = name; this.previewUrl = URL.createObjectURL(blob); this.previewSafeUrl = this.sanitizer.bypassSecurityTrustResourceUrl(this.previewUrl); }, error: error => this.uploadMessage = error.error?.message ?? 'No se pudo abrir el PDF.' });
  }
  closePreview(): void { if (this.previewUrl) URL.revokeObjectURL(this.previewUrl); this.previewUrl = null; this.previewSafeUrl = null; this.previewFileName = ''; }

  uploadFile(event: Event): void { const file = (event.target as HTMLInputElement).files?.[0]; if (file) this.uploadSelectedFile(file); }
  onFileDrop(event: DragEvent): void { event.preventDefault(); const file = event.dataTransfer?.files?.[0]; if (file) this.uploadSelectedFile(file); }
  private uploadSelectedFile(file: File): void { if (!this.session || this.isUploading || this.isLoadingFiles || this.isCategoryView) return; if (!this.canUploadCurrentPath) { this.uploadMessage = 'Para subir archivos, entre primero a procesos o pagos.'; return; } const extension = file.name.split('.').pop()?.toLowerCase() ?? ''; if (!this.allowedExtensions.includes(extension)) { this.uploadMessage = 'Formato no permitido. Solo se aceptan archivos PDF.'; return; } this.isUploading = true; this.uploadMessage = `Cargando ${file.name}…`; const data = new FormData(); data.append('file', file); data.append('path', this.currentPath); this.http.post<{ ok: boolean; folder: string; message: string }>(`${this.rootApi}/documents`, data, { headers: new HttpHeaders({ Authorization: `Bearer ${this.session.token}` }) }).subscribe({ next: ({ folder, message }) => { this.isUploading = false; this.currentFolder = folder; this.uploadMessage = message || 'Archivo cargado correctamente.'; this.loadFiles(false); }, error: error => { this.isUploading = false; this.uploadMessage = error.error?.message ?? 'No se pudo cargar el archivo.'; } }); }
  deleteFile(name: string): void { this.requestDelete({ name, type: 'file' }); }

  createPreRegistration(): void {
    if (!this.session) return;
    this.preRegistrationMessage = '';
    this.http.post(`${this.rootApi}/pre-registrations`, { cedula: this.cedula, role: this.preRegistrationRole }, { headers: new HttpHeaders({ Authorization: `Bearer ${this.session.token}` }) }).subscribe({
      next: () => { this.preRegistrationMessage = 'Pre-registro creado correctamente.'; this.cedula = ''; this.loadPreRegistrations(1); },
      error: (error) => this.preRegistrationMessage = error.error?.message ?? 'No se pudo crear el pre-registro.',
    });
  }

  loadPreRegistrations(page = this.preRegistrationPage): void {
    if (!this.session || !this.canPreRegister) return;
    this.isLoadingPreRegistrations = true;
    this.http.get<{ items: PreRegistration[]; page: number; pages: number }>(`${this.rootApi}/pre-registrations?page=${page}`, { headers: new HttpHeaders({ Authorization: `Bearer ${this.session.token}` }) }).subscribe({ next: data => { this.preRegistrations = data.items; this.preRegistrationPage = data.page; this.preRegistrationPages = data.pages; this.isLoadingPreRegistrations = false; }, error: () => { this.isLoadingPreRegistrations = false; } });
  }
  loadRoles(page = this.rolePage): void { if (!this.session || !this.canManageRoles) return; this.isLoadingRoles = true; this.http.get<{ roles: RoleItem[]; page: number; pages: number }>(`${this.rootApi}/roles?page=${page}`, { headers: new HttpHeaders({ Authorization: `Bearer ${this.session.token}` }) }).subscribe({ next: data => { this.roles = data.roles; this.rolePage = data.page; this.rolePages = data.pages; this.isLoadingRoles = false; }, error: error => { this.roleMessage = error.error?.message ?? 'No se pudieron cargar los roles.'; this.isLoadingRoles = false; } }); }
  createRole(): void { if (!this.session || !this.roleCode.trim() || !this.roleLabel.trim()) return; this.roleMessage = ''; this.http.post(`${this.rootApi}/roles`, { code: this.roleCode.trim(), label: this.roleLabel.trim(), parent_role: this.roleParent || null }, { headers: new HttpHeaders({ Authorization: `Bearer ${this.session.token}` }) }).subscribe({ next: () => { this.roleCode = ''; this.roleLabel = ''; this.roleParent = ''; this.roleMessage = 'Rol creado correctamente.'; this.loadRoles(); }, error: error => this.roleMessage = error.error?.message ?? 'No se pudo crear el rol.' }); }
  updateRole(role: RoleItem): void { if (!this.session) return; const label = window.prompt('Nombre del rol', role.label); if (!label?.trim()) return; this.http.patch(`${this.rootApi}/roles/${encodeURIComponent(role.code)}`, { label: label.trim(), parent_role: role.parent_role }, { headers: new HttpHeaders({ Authorization: `Bearer ${this.session.token}` }) }).subscribe({ next: () => this.loadRoles(), error: error => this.roleMessage = error.error?.message ?? 'No se pudo actualizar el rol.' }); }
  deactivateRole(role: RoleItem): void { if (!this.session || !confirm(`¿Desactivar ${role.label}?`)) return; this.http.patch(`${this.rootApi}/roles/${encodeURIComponent(role.code)}/deactivate`, {}, { headers: new HttpHeaders({ Authorization: `Bearer ${this.session.token}` }) }).subscribe({ next: () => this.loadRoles(), error: error => this.roleMessage = error.error?.message ?? 'No se pudo desactivar el rol.' }); }
  loadPurchases(): void { if (!this.session || !this.canDelegatePurchases) return; this.http.get<{ purchases: PurchaseItem[] }>(`${this.rootApi}/purchases`, { headers: new HttpHeaders({ Authorization: `Bearer ${this.session.token}` }) }).subscribe({ next: data => this.purchases = data.purchases, error: error => this.purchaseMessage = error.error?.message ?? 'No se pudieron cargar las compras.' }); }
  searchPurchaseUsers(query = ''): void { if (!this.session) return; this.isSearchingPurchaseUsers = true; const role = this.purchaseSubrole || this.purchaseRole; this.http.get<{ users: DelegatedUser[] }>(`${this.rootApi}/purchase-users?role=${encodeURIComponent(role)}&q=${encodeURIComponent(query)}`, { headers: new HttpHeaders({ Authorization: `Bearer ${this.session.token}` }) }).subscribe({ next: data => { this.purchaseUsers = data.users; this.isSearchingPurchaseUsers = false; }, error: error => { this.isSearchingPurchaseUsers = false; this.purchaseMessage = error.error?.message ?? 'No se pudieron buscar usuarios.'; } }); }
  openPurchaseSearch(): void { this.purchaseSearchModal = true; this.purchaseSearch = ''; }
  closePurchaseSearch(): void { this.purchaseSearchModal = false; }
  applyPurchaseSearch(): void { this.searchPurchaseUsers(this.purchaseSearch.trim()); }
  selectPurchaseUser(id: number | string | null): void { const selectedId = Number(id); this.selectedPurchaseUser = this.purchaseUsers.find(user => user.id === selectedId) ?? null; }
  createPurchase(): void { if (!this.session || !this.purchaseName.trim() || !this.selectedPurchaseUser) return; this.http.post<{ message: string }>(`${this.rootApi}/purchases`, { name: this.purchaseName.trim(), user_id: this.selectedPurchaseUser.id }, { headers: new HttpHeaders({ Authorization: `Bearer ${this.session.token}` }) }).subscribe({ next: data => { this.purchaseMessage = data.message; this.purchaseName = ''; this.purchaseSearch = ''; this.selectedPurchaseUser = null; this.purchaseUsers = []; this.loadPurchases(); }, error: error => this.purchaseMessage = error.error?.message ?? 'No se pudo delegar la compra.' }); }
  updatePurchase(item: PurchaseItem): void { if (!this.session) return; const name = window.prompt('Nombre de la compra', item.name); if (!name?.trim()) return; this.http.patch(`${this.rootApi}/purchases/${item.id}`, { name: name.trim() }, { headers: new HttpHeaders({ Authorization: `Bearer ${this.session.token}` }) }).subscribe({ next: () => this.loadPurchases(), error: error => this.purchaseMessage = error.error?.message ?? 'No se pudo actualizar la compra.' }); }
  deactivatePurchase(item: PurchaseItem): void { if (!this.session || !confirm(`¿Desactivar ${item.name}?`)) return; this.http.patch(`${this.rootApi}/purchases/${item.id}/deactivate`, {}, { headers: new HttpHeaders({ Authorization: `Bearer ${this.session.token}` }) }).subscribe({ next: () => this.loadPurchases(), error: error => this.purchaseMessage = error.error?.message ?? 'No se pudo desactivar la compra.' }); }

  togglePreRegistration(id: number): void {
    if (!this.session) return;
    this.http.patch(`${this.rootApi}/pre-registrations/${id}/toggle`, {}, { headers: new HttpHeaders({ Authorization: `Bearer ${this.session.token}` }) }).subscribe(() => this.loadPreRegistrations());
  }

  onSubmit(): void {
    if (this.isSubmitting) return;
    this.isSubmitting = true;
    this.loginMessage = '';
    this.http.post<SessionData>(`${this.apiBase}/login`, { username: this.username, password: this.password }).subscribe({
      next: (session) => {
        this.isSubmitting = false;
        this.session = session;
        localStorage.setItem(this.storageKey, JSON.stringify(session));
        this.authMode = 'home';
        this.navigate('/home');
        this.loadFiles();
        this.loadDocumentConfig();
        this.loadPreRegistrations();
      },
      error: () => { this.isSubmitting = false; this.loginMessage = 'invalid-credentials'; },
    });
  }

  private navigate(path: '/login' | '/home'): void {
    if (window.location.pathname !== path) window.history.replaceState({}, '', path);
  }
}
