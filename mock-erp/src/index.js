// Simulates a POS/ERP system that receives reservation syncs from Laravel.
// Includes 10% random timeout to exercise Laravel's Outbox + exponential-backoff retry.

const express = require('express');
const cors = require('cors');

const app = express();
app.use(express.json());
app.use(cors());

const reservations = new Map();
const failedRequests = [];

// POST /reservations — called by Laravel Outbox worker on confirmed reservation
app.post('/reservations', (req, res) => {
  const { reservation_id, table_number, party_size, reserved_at, customer_name } = req.body;

  if (!reservation_id) {
    return res.status(422).json({ error: 'reservation_id is required' });
  }

  // 10% simulated timeout — forces Laravel retry logic to activate
  if (Math.random() < 0.1) {
    failedRequests.push({ reservation_id, timestamp: new Date().toISOString() });
    console.log(`[MOCK ERP] Simulated timeout for reservation ${reservation_id}`);
    return res.status(408).json({ error: 'Request Timeout (simulated)' });
  }

  reservations.set(String(reservation_id), {
    reservation_id,
    table_number,
    party_size,
    reserved_at,
    customer_name,
    synced_at: new Date().toISOString(),
  });

  console.log(`[MOCK ERP] Reservation synced: #${reservation_id}`);
  return res.status(200).json({ message: 'Reservation received', reservation_id });
});

// GET /reservations — list all synced reservations
app.get('/reservations', (req, res) => {
  return res.json(Array.from(reservations.values()));
});

// GET /reservations/:id — check a specific reservation
app.get('/reservations/:id', (req, res) => {
  const record = reservations.get(req.params.id);
  if (!record) return res.status(404).json({ error: 'Not found' });
  return res.json(record);
});

// POST /reservations/:id/retry — manually trigger re-sync (demo use)
app.post('/reservations/:id/retry', (req, res) => {
  console.log(`[MOCK ERP] Manual retry requested for reservation ${req.params.id}`);
  return res.json({ message: `Retry queued for reservation ${req.params.id}` });
});

// GET /failed — view all simulated failures
app.get('/failed', (req, res) => {
  return res.json(failedRequests);
});

// GET /health — container health check
app.get('/health', (req, res) => res.json({ status: 'ok' }));

const PORT = process.env.PORT || 3000;
app.listen(PORT, '0.0.0.0', () => {
  console.log(`[MOCK ERP] Listening on port ${PORT}`);
});
