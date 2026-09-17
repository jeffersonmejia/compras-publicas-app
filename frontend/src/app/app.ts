import { Component } from '@angular/core';
import { FormsModule } from '@angular/forms';

@Component({
  selector: 'app-root',
  imports: [FormsModule],
  templateUrl: './app.html',
  styleUrl: './app.scss',
})
export class App {
  username = '';
  password = '';
  remember = false;
  showPassword = false;
  loginMessage = '';

  onSubmit(): void {
    // El backend PHP asignará loginMessage únicamente si las credenciales son inválidas.
  }
}
