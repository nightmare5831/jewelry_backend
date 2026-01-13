<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SellerSettingsController extends Controller
{
    /**
     * Update seller's Mercado Pago connection status
     */
    public function updateMercadoPago(Request $request)
    {
        $request->validate([
            'connected' => 'required|boolean',
        ]);

        $user = Auth::user();

        if (!$user->isSeller()) {
            return response()->json(['error' => 'Only sellers can manage Mercado Pago'], 403);
        }

        $user->update([
            'mercadopago_connected' => $request->connected,
        ]);

        return response()->json([
            'message' => $request->connected
                ? 'Mercado Pago account connected successfully'
                : 'Mercado Pago account disconnected',
            'seller' => $user,
        ]);
    }

    /**
     * Get seller's Mercado Pago connection status
     */
    public function getMercadoPagoStatus()
    {
        $user = Auth::user();
        return response()->json([
            'connected' => $user->mercadopago_connected ?? false,
            'email' => $user->email, // Seller uses their account email for MP
        ]);
    }
}
