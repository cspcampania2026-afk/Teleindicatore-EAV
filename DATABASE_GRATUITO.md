# Database esterno gratuito: Render Free + Neon Free

Questa versione può usare PostgreSQL esterno per conservare utenti, password in forma di hash, avvisi, registro, resoconto, interventi sulle corse e stato della coda Telegram. Non richiede un disco persistente su Render.

## 1. Crea il database su Neon

- Apri https://neon.com e registrati scegliendo il piano **Free**. Non attivare upgrade a pagamento.
- Crea un progetto, per esempio `teleindicatore-csp`, e un database PostgreSQL. Puoi mantenere il nome proposto `neondb`.
- Scegli, se possibile, una regione vicina a quella del servizio Render.
- Apri **Connect** e copia la **connection string** PostgreSQL. Puoi usare **Connection pooling**: il nome del server normalmente contiene `-pooler`.
- Copia il solo URL completo, senza `psql`, senza virgolette e senza ritorni a capo. Mantieni i parametri `sslmode=require` e, se presente, `channel_binding=require`.

Esempio NON utilizzabile, con segnaposto:

```
postgresql://UTENTE:PASSWORD@SERVER-pooler.REGIONE.neon.tech/neondb?sslmode=require&channel_binding=require
```

L'URL contiene una password del database. Incollalo soltanto nei segreti di Render: non inviarlo in chat, non inserirlo nei file del sito e non caricarlo su GitHub. Non serve una API key Neon.

## 2. Pubblica il pacchetto completo

Estrai lo ZIP e carica **tutto il contenuto** di `teleindicatore` nella posizione dei file attuali nel repository collegato a Render. Sono indispensabili anche `database.php` e il **Dockerfile aggiornato**, che installa il driver PostgreSQL. Non caricare soltanto lo ZIP o il file HTML.

Su Render mantieni il servizio con istanza **Free** e non aggiungere dischi. In **Environment** aggiungi:

| Variabile | Valore |
|---|---|
| `DATABASE_URL` | L'URL PostgreSQL completo copiato da Neon |

Conserva le altre variabili già configurate, comprese `CSP_ADMIN_USER`, la password admin e quelle Telegram. Lascia `CSP_DATA_DIR=/var/lib/csp-data`: con PostgreSQL serve soltanto per file temporanei, non per conservare il database.

Salva e pubblica l'ultima versione. Attendi **Live** prima di effettuare il primo accesso.

## 3. Verifica e crea gli utenti

- Apri `https://teleindicatore-eav.onrender.com/admin.php`.
- Su un database nuovo accedi con le credenziali admin attualmente configurate su Render: il sistema crea le tabelle e il primo amministratore.
- Apri **Il mio account / Gestione utenti** e verifica la scritta **PostgreSQL esterno · indipendente dal disco Render**.
- Crea gli operatori e gli eventuali altri amministratori come spiegato in `GESTIONE_UTENTI.md`.
- Dopo un successivo deploy, verifica che gli utenti siano ancora presenti. Può essere necessario rifare il login: le sessioni del browser sono temporanee, gli account nel database esterno restano conservati.

Senza `DATABASE_URL` il programma continua a usare SQLite locale. Non significa che i dati siano diventati persistenti: controlla sempre la scritta dell'archivio. Se `DATABASE_URL` è impostata ma non funziona, il programma segnala l'errore e non passa a un database locale separato.

## Dati precedenti

Collegare un database nuovo non copia automaticamente il vecchio SQLite. Se avevi già creato utenti, avvisi o resoconti, non considerarli trasferiti: sul database nuovo dovrai ricrearli oppure organizzare una migrazione da un backup prima di abbandonare il precedente archivio. Non cancellare il vecchio database se contiene dati da conservare.

## Costi e limiti

Neon offre un piano Free con quote di spazio, calcolo e traffico: controlla **Usage** nella console. Se raggiungi un limite, il servizio può limitare o sospendere le risorse fino al ripristino della quota; non risolvere attivando un piano a pagamento se vuoi restare a costo zero. Il monitor e le pagine aggiornate frequentemente consumano risorse anche quando non cambia alcun treno.

Il database esterno conserva i dati indipendentemente dai riavvii di Render. **Non mantiene acceso Render Free**: quando il servizio è sospeso, il monitor non legge EAV e non invia Telegram. Non recupera automaticamente ogni variazione avvenuta durante la sospensione. Questa configurazione gratuita non garantisce un servizio h24.

Non è stato creato alcun account esterno e non è stato attivato alcun servizio a pagamento. Per completare il collegamento occorre inserire il tuo URL su Render.

Fonti consultate il 16 ottobre 2026:
- https://neon.com/blog/neon-free-plan-1-gb-per-project
- https://neon.com/docs/get-started-with-neon/connect-neon
- https://render.com/docs/free
