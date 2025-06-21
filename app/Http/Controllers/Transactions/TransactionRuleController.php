<?php

namespace App\Http\Controllers\Transactions;

use App\Http\Controllers\Controller;
use App\Models\TransactionRule;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * @deprecated This controller is deprecated. Use App\Http\Controllers\RuleEngine\RuleController instead.
 * 
 * This class handles the legacy transaction rules functionality.
 * New rule functionality is handled by the RuleController in the RuleEngine namespace.
 */

class TransactionRuleController extends Controller
{
    public function index()
    {
        $rules = TransactionRule::where('user_id', auth()->id())
            ->orderBy('order')
            ->get();

        return Inertia::render('rules/index', [
            'rules' => $rules,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'condition_type' => 'required|string|in:amount,iban,description',
            'condition_operator' => 'required|string|in:equals,contains,greater_than,less_than',
            'condition_value' => 'required|string|max:255',
            'action_type' => 'required|string|in:add_tag,set_category,set_type',
            'action_value' => 'required|string|max:255',
            'is_active' => 'boolean',
        ]);

        $validated['user_id'] = auth()->id();
        $validated['order'] = TransactionRule::where('user_id', auth()->id())->max('order') + 1;

        TransactionRule::create($validated);

        return redirect()->back();
    }

    public function update(Request $request, TransactionRule $rule)
    {
        $this->authorize('update', $rule);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'condition_type' => 'required|string|in:amount,iban,description',
            'condition_operator' => 'required|string|in:equals,contains,greater_than,less_than',
            'condition_value' => 'required|string|max:255',
            'action_type' => 'required|string|in:add_tag,set_category,set_type',
            'action_value' => 'required|string|max:255',
            'is_active' => 'boolean',
        ]);

        $rule->update($validated);

        return redirect()->back();
    }

    public function destroy(TransactionRule $rule)
    {
        $this->authorize('delete', $rule);
        $rule->delete();

        return redirect()->back();
    }

    public function reorder(Request $request)
    {
        $validated = $request->validate([
            'rules' => 'required|array',
            'rules.*.id' => 'required|exists:transaction_rules,id',
            'rules.*.order' => 'required|integer|min:0',
        ]);

        foreach ($validated['rules'] as $ruleData) {
            $rule = TransactionRule::find($ruleData['id']);
            $this->authorize('update', $rule);
            $rule->update(['order' => $ruleData['order']]);
        }

        return response()->json(['message' => 'Rules reordered successfully']);
    }
}
