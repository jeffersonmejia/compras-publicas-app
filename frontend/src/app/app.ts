import { HttpClient } from '@angular/common/http';
import { Component, inject } from '@angular/core';
import { FormsModule } from '@angular/forms';

@Component({
  selector: 'app-root',
  imports: [FormsModule],
  templateUrl: './app.html',
  styleUrl: './app.scss',
})
export class App {
  private readonly http = inject(HttpClient);

  username = '';
  password = '';
  remember = false;
  showPassword = false;
  loginMessage = '';
  isSubmitting = false;

  onSubmit(): void {
    if (this.isSubmitting) return;

    this.isSubmitting = true;
    this.loginMessage = '';
    const apiUrl = `http://${window.location.hostname}/compras-publicas-api/api/auth/login`;
    this.http.post<{ user: { username: string } }>(apiUrl, {
      username: this.username,
      password: this.password,
    }).subscribe({
      next: () => this.isSubmitting = false,
      error: () => {
        this.isSubmitting = false;
        this.loginMessage = 'invalid-credentials';
      },
    });
  }
}
